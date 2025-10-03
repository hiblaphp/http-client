<?php

namespace Hibla\Http\Testing\Exceptions;

/**
 * Thrown when a request doesn't match any mocked expectations.
 */
class UnexpectedRequestException extends MockException
{
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

        // Show request body if present
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

        // Show request headers
        if (isset($options[CURLOPT_HTTPHEADER]) && !empty($options[CURLOPT_HTTPHEADER])) {
            $lines[] = "  Request Headers:";
            foreach ($options[CURLOPT_HTTPHEADER] as $header) {
                $lines[] = "    - {$header}";
            }
        }

        // Show available mocks
        if (!empty($availableMocks)) {
            $lines[] = "";
            $lines[] = "Available mocks:";
            foreach ($availableMocks as $index => $mock) {
                $lines[] = "  Mock #{$index}:";
                $lines[] = "    URL Pattern: " . ($mock->getUrlPattern() ?? '*');
                $lines[] = "    Method: " . $mock->getMethod();

                // Show expectations
                $mockArray = $mock->toArray();
                if (!empty($mockArray['jsonMatcher'])) {
                    $lines[] = "    Expected JSON: " . json_encode($mockArray['jsonMatcher']);
                }
                if (!empty($mockArray['headerMatchers'])) {
                    $lines[] = "    Expected Headers:";
                    foreach ($mockArray['headerMatchers'] as $name => $value) {
                        $lines[] = "      - {$name}: {$value}";
                    }
                }
            }
        }

        return implode("\n", $lines);
    }
}