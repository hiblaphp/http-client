<?php

namespace Hibla\Http\Testing\Exceptions;

use Hibla\Http\Testing\MockedRequest;

/**
 * Thrown when a request doesn't match any mocked expectations.
 */
class UnexpectedRequestException extends MockException
{
    /**
     * @param array<int|string, mixed> $options
     * @param array<MockedRequest> $availableMocks
     */
    public function __construct(
        string $message = 'No mock matched the request',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $url = null,
        public readonly string $method = '',
        public readonly array $options = [],
        public readonly array $availableMocks = []
    ) {
        parent::__construct($message, $code, $previous, $url);
    }

    /**
     * Create exception with detailed mismatch information.
     * @param array<int|string, mixed> $options
     * @param array<MockedRequest> $availableMocks
     */
    public static function noMatchFound(
        string $method,
        string $url,
        array $options,
        array $availableMocks
    ): self {
        $message = self::buildDetailedMessage($method, $url, $options, $availableMocks);

        return new self($message, 0, null, $url, $method, $options, $availableMocks);
    }

    /**
     * @param array<int|string, mixed> $options
     * @param array<MockedRequest> $availableMocks
     */
    private static function buildDetailedMessage(
        string $method,
        string $url,
        array $options,
        array $availableMocks
    ): string {
        $lines = [];
        $lines[] = "No mock matched the request:";
        $lines[] = "";
        $lines[] = "  Method: {$method}";
        $lines[] = "  URL: {$url}";

        self::addRequestBodyLines($lines, $options);
        self::addRequestHeadersLines($lines, $options);
        self::addAvailableMocksLines($lines, $availableMocks);

        return implode("\n", $lines);
    }

    /**
     * Adds lines detailing the request body (if present) to the message lines.
     * @param string[] $lines
     * @param array<int|string, mixed> $options
     */
    private static function addRequestBodyLines(array &$lines, array $options): void
    {
        if (isset($options[CURLOPT_POSTFIELDS])) {
            $body = $options[CURLOPT_POSTFIELDS];
            if (is_string($body)) {
                $decoded = json_decode($body, true);
                if ($decoded !== null) {
                    $lines[] = "  Request JSON: " . json_encode($decoded, JSON_PRETTY_PRINT);
                } else {
                    $lines[] = "  Request Body: " . substr($body, 0, 200);
                }
            }
        }
    }

    /**
     * Adds lines detailing the request headers (if present) to the message lines.
     * @param string[] $lines
     * @param array<int|string, mixed> $options
     */
    private static function addRequestHeadersLines(array &$lines, array $options): void
    {
        if (isset($options[CURLOPT_HTTPHEADER]) && is_array($options[CURLOPT_HTTPHEADER]) && $options[CURLOPT_HTTPHEADER] !== []) {
            $lines[] = "  Request Headers:";
            foreach ($options[CURLOPT_HTTPHEADER] as $header) {
                if (is_string($header)) {
                    $lines[] = "    - {$header}";
                }
            }
        }
    }

    /**
     * Adds lines detailing the available mocks and their expectations to the message lines.
     * @param string[] $lines
     * @param array<MockedRequest> $availableMocks
     */
    private static function addAvailableMocksLines(array &$lines, array $availableMocks): void
    {
        if ($availableMocks !== []) {
            $lines[] = "";
            $lines[] = "Available mocks:";
            foreach ($availableMocks as $index => $mock) {
                $lines[] = "  Mock #{$index}:";
                $lines[] = "    URL Pattern: " . ($mock->getUrlPattern() ?? '*');
                $lines[] = "    Method: " . $mock->getMethod();

                self::addMockExpectationsLines($lines, $mock);
            }
        }
    }

    /**
     * Adds lines detailing a single mock's expectations to the message lines.
     * @param string[] $lines
     * @param MockedRequest $mock
     */
    private static function addMockExpectationsLines(array &$lines, MockedRequest $mock): void
    {
        $mockArray = $mock->toArray();
        if (isset($mockArray['jsonMatcher']) && is_array($mockArray['jsonMatcher']) && $mockArray['jsonMatcher'] !== []) {
            $lines[] = "    Expected JSON: " . json_encode($mockArray['jsonMatcher']);
        }
        if (isset($mockArray['headerMatchers']) && is_array($mockArray['headerMatchers']) && $mockArray['headerMatchers'] !== []) {
            $lines[] = "    Expected Headers:";
            foreach ($mockArray['headerMatchers'] as $name => $value) {
                $displayValue = is_scalar($value) ? (string)$value : '(non-scalar value)';
                $lines[] = "      - {$name}: {$displayValue}";
            }
        }
    }
}
