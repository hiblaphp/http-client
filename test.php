<?php

use Hibla\Http\Http;
use Hibla\Http\SSE\SSEEvent;
use Hibla\Http\SSE\SSEReconnectConfig;

require 'vendor/autoload.php';

$http = Http::startTesting();

// First attempt: SSE connection fails
$http->mock('GET')
    ->url('https://api.example.com/events')
    ->respondWithSSE([
        ['event' => 'error', 'data' => 'Connection failed'],
        ['event' => 'retry', 'data' => '1'],
    ])
    ->register();

// Second attempt: SSE connection succeeds
$http->mock('GET')
    ->url('https://api.example.com/events')
    ->respondWithSSE([
        ['data' => 'Hello World', 'id' => '1'],
    ])
    ->register();

$events = [];
$errors = [];

$reconnectConfig = new SSEReconnectConfig(
    enabled: true,
    maxAttempts: 3,
    initialDelay: 0.1
);

echo "Starting SSE test...\n";

Http::sse(
    'https://api.example.com/events',
    onEvent: function (SSEEvent $event) use (&$events) {
        echo "✓ Event: {$event->data}\n";
        $events[] = $event->data;
    },
    onError: function (string $error) use (&$errors) {
        echo "✗ Error: {$error}\n";
        $errors[] = $error;
    },
    reconnectConfig: $reconnectConfig
)->await();

echo "\nTest completed!\n";
echo "Errors: " . count($errors) . " (expected: 1)\n";
echo "Events: " . count($events) . " (expected: 1)\n";

Http::stopTesting();