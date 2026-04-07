<?php

declare(strict_types=1);

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEParser;

it('ignores id values containing NULL and falls back to the last valid id', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("id: valid-id\nid: bad\x00id\ndata: Test\n\n"));

    expect($events[0]->id)->toBe('valid-id');
});

it('returns null id when all id values contain NULL', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("id: \x00\nid: also\x00bad\ndata: Test\n\n"));

    expect($events[0]->id)->toBeNull();
});

it('ignores id with NULL even when it appears before a valid id', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("id: bad\x00id\nid: valid-id\ndata: Test\n\n"));

    expect($events[0]->id)->toBe('valid-id');
});

it('treats an id of only NULL as absent', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("id: \x00\ndata: Test\n\n"));

    expect($events[0]->id)->toBeNull();
});

it('preserves a valid id when NULL-containing ids are interspersed', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse(
        "id: first-valid\nid: bad\x00one\nid: second-valid\nid: bad\x00two\ndata: Test\n\n"
    ));

    expect($events[0]->id)->toBe('second-valid');
});

it('parses a single event', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data: Hello World\n\n"));

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(SSEEvent::class)
        ->and($events[0]->data)->toBe('Hello World')
    ;
});

it('parses multiple events', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data: First\n\ndata: Second\n\ndata: Third\n\n"));

    expect($events)->toHaveCount(3)
        ->and($events[0]->data)->toBe('First')
        ->and($events[1]->data)->toBe('Second')
        ->and($events[2]->data)->toBe('Third')
    ;
});

it('parses event with id and event type', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("id: 123\nevent: custom\ndata: Test\n\n"));

    expect($events[0]->id)->toBe('123')
        ->and($events[0]->event)->toBe('custom')
        ->and($events[0]->data)->toBe('Test')
    ;
});

it('parses all field types together', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("id: event-1\nevent: notification\ndata: Hello\nretry: 3000\n\n"));

    expect($events[0]->id)->toBe('event-1')
        ->and($events[0]->event)->toBe('notification')
        ->and($events[0]->data)->toBe('Hello')
        ->and($events[0]->retry)->toBe(3000)
    ;
});

it('parses multiline data field', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data: Line 1\ndata: Line 2\ndata: Line 3\n\n"));

    expect($events[0]->data)->toBe("Line 1\nLine 2\nLine 3");
});

it('parses multiple events with mixed fields', function () {
    $parser = new SSEParser();

    $sseData = <<<SSE
id: 1
event: message
data: First event

id: 2
event: update
data: Second event
data: with multiple lines

: this is a comment

id: 3
data: Third event


SSE;

    $events = iterator_to_array($parser->parse($sseData));

    expect($events)->toHaveCount(3);

    expect($events[0]->id)->toBe('1');
    expect($events[0]->event)->toBe('message');
    expect($events[0]->data)->toBe('First event');

    expect($events[1]->id)->toBe('2');
    expect($events[1]->event)->toBe('update');
    expect($events[1]->data)->toBe("Second event\nwith multiple lines");

    expect($events[2]->id)->toBe('3');
    expect($events[2]->data)->toBe('Third event');
});

it('parses retry field alongside data', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("retry: 5000\ndata: Test\n\n"));

    expect($events[0]->retry)->toBe(5000)
        ->and($events[0]->data)->toBe('Test')
    ;
});

it('does not dispatch event when block has only a retry field and no data', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("retry: 5000\n\n"));

    expect($events)->toHaveCount(0);
});

it('treats non-numeric retry as null', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("retry: not-a-number\ndata: Test\n\n"));

    expect($events[0]->retry)->toBeNull();
});

it('treats float retry as null', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("retry: 3.5\ndata: Test\n\n"));

    expect($events[0]->retry)->toBeNull();
});

it('treats negative retry as null', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("retry: -1000\ndata: Test\n\n"));

    expect($events[0]->retry)->toBeNull();
});

it('ignores comment lines', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse(": This is a comment\ndata: Real data\n: Another comment\n\n"));

    expect($events)->toHaveCount(1)
        ->and($events[0]->data)->toBe('Real data')
    ;
});

it('yields no events for comment-only input', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse(": comment 1\n: comment 2\n: comment 3\n\n"));

    expect($events)->toHaveCount(0);
});

it('yields no events for whitespace-only input', function () {
    $parser = new SSEParser();

    expect(iterator_to_array($parser->parse("   \n\n   \n\n")))->toHaveCount(0);
});

it('yields no events for blank-line-only input', function () {
    $parser = new SSEParser();

    expect(iterator_to_array($parser->parse("\n\n\n\n")))->toHaveCount(0);
});

it('does not dispatch event when block has id and event type but no data field', function () {
    // Per spec: data buffer is empty → must not dispatch.
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("id: 1\nevent: ping\n\n"));

    expect($events)->toHaveCount(0);
});

it('buffers an incomplete event until terminator arrives', function () {
    $parser = new SSEParser();

    $first = iterator_to_array($parser->parse('data: Par'));
    $second = iterator_to_array($parser->parse("tial\n\n"));

    expect($first)->toHaveCount(0)
        ->and($second)->toHaveCount(1)
        ->and($second[0]->data)->toBe('Partial')
    ;
});

it('handles multiple incomplete chunks', function () {
    $parser = new SSEParser();

    iterator_to_array($parser->parse('data: '));
    iterator_to_array($parser->parse('First '));
    iterator_to_array($parser->parse('part'));
    $events = iterator_to_array($parser->parse("\n\n"));

    expect($events)->toHaveCount(1)
        ->and($events[0]->data)->toBe('First part')
    ;
});

it('handles multiple events arriving across chunks with buffering', function () {
    $parser = new SSEParser();

    $all = [];
    $all = array_merge($all, iterator_to_array($parser->parse("data: First\n")));
    $all = array_merge($all, iterator_to_array($parser->parse("\ndata: Sec")));
    $all = array_merge($all, iterator_to_array($parser->parse("ond\n\ndata: Thi")));
    $all = array_merge($all, iterator_to_array($parser->parse("rd\n\n")));

    expect($all)->toHaveCount(3)
        ->and($all[0]->data)->toBe('First')
        ->and($all[1]->data)->toBe('Second')
        ->and($all[2]->data)->toBe('Third')
    ;
});

it('reset clears the internal buffer', function () {
    $parser = new SSEParser();

    $before = iterator_to_array($parser->parse('data: Stale'));
    expect($before)->toHaveCount(0);

    $parser->reset();

    $after = iterator_to_array($parser->parse("data: Fresh\n\n"));
    expect($after)->toHaveCount(1)
        ->and($after[0]->data)->toBe('Fresh')
    ;
});

it('reset clears the BOM-stripped flag so a new stream BOM is handled', function () {
    $parser = new SSEParser();

    iterator_to_array($parser->parse("data: First\n\n"));
    $parser->reset();

    $events = iterator_to_array($parser->parse("\xEF\xBB\xBFdata: After reset\n\n"));
    expect($events)->toHaveCount(1)
        ->and($events[0]->data)->toBe('After reset')
    ;
});

it('handles field without colon', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data\n\n"));

    expect($events[0]->data)->toBe('');
});

it('handles field with colon but no value', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data:\n\n"));

    expect($events[0]->data)->toBe('');
});

it('strips only the first leading space from field value', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data:  Value with spaces  \n\n"));

    expect($events[0]->data)->toBe(' Value with spaces  ');
});

it('strips the single leading space from field value', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data: Value\n\n"));

    expect($events[0]->data)->toBe('Value');
});

it('handles field values containing colons', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data: http://example.com:8080/path\n\n"));

    expect($events[0]->data)->toBe('http://example.com:8080/path');
});

it('last value wins for duplicate field names', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("id: first\nid: second\nid: third\ndata: Test\n\n"));

    expect($events[0]->id)->toBe('third')
        ->and($events[0]->rawFields['id'])->toBe(['first', 'second', 'third'])
    ;
});

it('stores raw fields including custom ones', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("id: 1\nevent: test\ndata: Hello\ncustom: value\n\n"));

    expect($events[0]->rawFields)->toHaveKey('id')
        ->and($events[0]->rawFields)->toHaveKey('event')
        ->and($events[0]->rawFields)->toHaveKey('data')
        ->and($events[0]->rawFields)->toHaveKey('custom')
        ->and($events[0]->rawFields['custom'])->toBe(['value'])
    ;
});

it('handles CRLF line endings', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data: Test\r\n\r\n"));

    expect($events)->toHaveCount(1)
        ->and($events[0]->data)->toBe('Test')
    ;
});

it('handles bare CR line endings', function () {
    // Per spec, a bare \r not followed by \n is a valid line ending.
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data: Test\r\r"));

    expect($events)->toHaveCount(1)
        ->and($events[0]->data)->toBe('Test')
    ;
});

it('handles mixed CRLF and LF line endings', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data: First\r\n\r\ndata: Second\n\n"));

    expect($events)->toHaveCount(2)
        ->and($events[0]->data)->toBe('First')
        ->and($events[1]->data)->toBe('Second')
    ;
});

it('handles mixed bare CR and LF line endings', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("data: First\r\rdata: Second\n\n"));

    expect($events)->toHaveCount(2)
        ->and($events[0]->data)->toBe('First')
        ->and($events[1]->data)->toBe('Second')
    ;
});

it('strips a leading UTF-8 BOM from the first chunk', function () {
    $parser = new SSEParser();

    $events = iterator_to_array($parser->parse("\xEF\xBB\xBFdata: Hello\n\n"));

    expect($events)->toHaveCount(1)
        ->and($events[0]->data)->toBe('Hello')
    ;
});

it('does not strip BOM from subsequent chunks', function () {
    $parser = new SSEParser();

    iterator_to_array($parser->parse("data: First\n\n"));
    $events = iterator_to_array($parser->parse("\xEF\xBB\xBFdata: Second\n\n"));

    expect($events)->toHaveCount(0);
});

it('handles very long data fields', function () {
    $parser = new SSEParser();
    $longData = str_repeat('x', 10000);

    $events = iterator_to_array($parser->parse("data: {$longData}\n\n"));

    expect($events[0]->data)->toBe($longData);
});
