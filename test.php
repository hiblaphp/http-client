<?php

use Hibla\Http\Http;
use Hibla\Http\SSE\SSEEvent;

require 'vendor/autoload.php';

Http::startTesting();

Http::mock()
    ->url("*")
    ->sseInfiniteStream(
        eventGenerator: fn($i) => [
            'data' => json_encode(['index' => $i, 'timestamp' => time()]),
            'id' => (string)$i,
            'event' => 'message',
        ],
        intervalSeconds: 0.1,
        maxEvents: 10
    )
    // ->sseWithPeriodicEvents([
    //     [
    //         'data' => json_encode(['index' => 100, 'timestamp' => time()]),
    //         'id' => '100',
    //         'event' => 'message'
    //     ],
    // ], 1.0)
    ->persistent()
    ->register();

Http::sseDataFormat('array')
    ->sseReconnect(
        enabled: true,
        maxAttempts: 5,
        initialDelay: 1.0,
        maxDelay: 60,
        backoffMultiplier: 1.0,
    )->sse(
        "http://localhost:8080/sse",
        onEvent: function ($data) {
            print_r($data);
        },
        onError: function ($error) {
            print_r($error);
        }
    );
