<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

/**
 * Parses raw SSE data chunks into SSEEvent objects.
 *
 * Compliant with the WHATWG SSE specification:
 * https://html.spec.whatwg.org/multipage/server-sent-events.html
 *
 * ── Stream decoding ───────────────────────────────────────────────────────────
 * The stream is decoded as UTF-8. One leading UTF-8 BOM (0xEF 0xBB 0xBF) is
 * stripped from the very first chunk if present, and never again after that.
 *
 * ── Line endings ──────────────────────────────────────────────────────────────
 * Three line-ending forms are recognised, per spec:
 *   CRLF  — U+000D U+000A
 *   LF    — U+000A alone
 *   CR    — U+000D alone (not followed by LF)
 * All three are normalised to LF before parsing.
 *
 * ── Event boundaries ──────────────────────────────────────────────────────────
 * Events are delimited by a blank line (two consecutive LFs after normalisation).
 * Data that has not yet been followed by a blank line is held in an internal
 * buffer and carried forward to the next chunk.
 *
 * ── Field parsing ─────────────────────────────────────────────────────────────
 * Each non-empty line within an event block is parsed as a field:
 *   - Lines starting with ':' are comments and are ignored entirely.
 *   - Lines containing ':' are split at the FIRST colon; if the value begins
 *     with a single U+0020 SPACE it is stripped (exactly one, no more).
 *   - Lines without ':' are treated as a field name with an empty string value.
 *
 * Recognised field names (compared literally, no case-folding):
 *   data   — appended to the data buffer; multiple lines are joined with LF.
 *   event  — sets the event type; last value wins for duplicates.
 *   id     — sets the event ID; last value wins for duplicates.
 *             Per spec section 9.2.6, id values containing U+0000 NULL are ignored
 *             entirely — the last valid (NULL-free) id value wins.
 *   retry  — sets the reconnection time in ms; value must be ASCII digits only
 *             (no floats, negatives, or hex) — non-conforming values are ignored.
 *
 * Unknown field names are collected in SSEEvent::$rawFields but otherwise
 * ignored, matching the spec's "process the field" fallback.
 *
 * ── Dispatch rules ────────────────────────────────────────────────────────────
 * When a blank line is encountered the accumulated fields are evaluated:
 *   - If no 'data' field was present (empty data buffer), the block is silently
 *     discarded — no SSEEvent is yielded. This means retry-only or id-only
 *     blocks do not produce events.
 *   - Otherwise an SSEEvent is yielded with the collected field values.
 *
 * ── End of stream ─────────────────────────────────────────────────────────────
 * Per spec, any data in the buffer that has not been terminated by a blank line
 * at end of stream is discarded. Callers must not rely on the buffer being
 * flushed automatically; reset() clears it explicitly when reusing the parser
 * across reconnections.
 *
 * ── Last event ID persistence ─────────────────────────────────────────────────
 * Per spec section 9.2.6, the last event ID buffer is not reset between events — if
 * event A sets an id and event B has no id field, event B should still carry
 * the last seen id. This persistence is the responsibility of the caller;
 * SSEEvent::$id will be null when no valid id field was present in that block.
 */
class SSEParser
{
    private string $buffer = '';

    private bool $bomStripped = false;

    /**
     * Parses incoming SSE data chunks and yields events.
     *
     * @param  string  $chunk  Raw SSE data chunk.
     * @return \Generator<SSEEvent>
     */
    public function parse(string $chunk): \Generator
    {
        // Strip one leading UTF-8 BOM from the very first chunk, per spec.
        if (! $this->bomStripped) {
            $this->bomStripped = true;
            if (str_starts_with($chunk, "\xEF\xBB\xBF")) {
                $chunk = substr($chunk, 3);
            }
        }

        $this->buffer .= $chunk;

        // Normalise all three spec-defined line endings (CRLF, CR, LF) to LF.
        $normalized = str_replace(["\r\n", "\r"], "\n", $this->buffer);
        $parts = explode("\n\n", $normalized);

        if (! str_ends_with($normalized, "\n\n")) {
            $this->buffer = array_pop($parts) ?? '';
        } else {
            $this->buffer = '';
        }

        foreach ($parts as $eventData) {
            if ($eventData === '') {
                continue;
            }

            $event = $this->parseEvent($eventData);
            if ($event !== null) {
                yield $event;
            }
        }
    }

    /**
     * Resets internal state. Useful when reusing the parser across reconnections.
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->bomStripped = false;
    }

    /**
     * Parses a single SSE event block and returns an SSEEvent, or null if the
     * data buffer is empty (per spec, empty-data blocks must not be dispatched).
     */
    private function parseEvent(string $eventData): ?SSEEvent
    {
        $lines = explode("\n", $eventData);

        /** @var array<string, list<string>> $fields */
        $fields = [];

        foreach ($lines as $line) {
            // Lines starting with ':' are comments — ignore them.
            if (str_starts_with($line, ':')) {
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $field = substr($line, 0, $colonPos);
                $value = substr($line, $colonPos + 1);
                // Strip exactly one leading space, per spec.
                if (str_starts_with($value, ' ')) {
                    $value = substr($value, 1);
                }
            } else {
                // No colon — whole line is the field name, value is empty string.
                $field = $line;
                $value = '';
            }

            $field = trim($field);
            if ($field === '') {
                continue;
            }

            $fields[$field][] = $value;
        }

        if (\count($fields) === 0) {
            return null;
        }

        // Per spec, if the data buffer is empty, the event must not be dispatched.
        $dataLines = $fields['data'] ?? [];
        if (\count($dataLines) === 0) {
            return null;
        }

        // Build the data string: join lines with LF, then strip one trailing LF per spec.
        $data = implode("\n", $dataLines);

        // Per spec section 9.2.6: id field values containing U+0000 NULL must be ignored
        // entirely. Filter them out first so the last *valid* id value wins.
        $idValues = array_filter(
            $fields['id'] ?? [],
            fn (string $v) => ! str_contains($v, "\0")
        );

        $eventValues = $fields['event'] ?? [];
        $retryValues = $fields['retry'] ?? [];

        $id = end($idValues) !== false ? end($idValues) : null;
        $event = end($eventValues) !== false ? end($eventValues) : null;
        $retryValue = end($retryValues) !== false ? end($retryValues) : null;

        // Per spec, retry must consist solely of ASCII digits — no floats, negatives, or hex.
        $retry = ($retryValue !== null && ctype_digit($retryValue))
            ? (int) $retryValue
            : null;

        return new SSEEvent(
            id: $id,
            event: $event,
            data: $data,
            retry: $retry,
            rawFields: $fields
        );
    }
}
