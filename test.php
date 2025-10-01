<?php

use Hibla\Http\Http;
use Hibla\Http\SSE\SSEEvent;
use function Hibla\await;

require 'vendor/autoload.php';

Http::startTesting();

Http::mock()
    ->url('https://chat.example.com/messages')
    ->respondWithSSE([
        [
            'id' => '1',
            'event' => 'message',
            'data' => json_encode(['user' => 'Alice', 'text' => 'Hello'])
        ],
        [
            'id' => '2',
            'event' => 'message',
            'data' => json_encode(['user' => 'Bob', 'text' => 'Hi there'])
        ],
        [
            'id' => '3',
            'event' => 'message',
            'data' => json_encode(['user' => 'Charlie', 'text' => 'Hey'])
        ]
    ])
    ->register();

$promise = Http::sseDataFormat('json')->sse(
    'https://chat.example.com/messages',
    onEvent: function ($event) use (&$messages) {
       print_r($event);
    },
    onError: fn($error) => print "Error: {$error}\n"
);

await($promise);

Http::assertSSEConnectionMade('https://chat.example.com/messages');
Http::assertRequestCount(1);

echo "✓ All tests passed!\n";
