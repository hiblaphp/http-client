<?php

use Hibla\EventLoop\Loop;
use Hibla\Http\Http;

require 'vendor/autoload.php';

Http::startTesting();

echo "Testing infinite stream (limited to 10 events)...\n";

Http::mock()
    ->url("*")
    ->sseInfiniteStream(
        eventGenerator: fn($i) => [
            'data' => json_encode(['index' => $i, 'timestamp' => time()]),
            'id' => (string)$i,
            'event' => 'message',
        ],
        intervalSeconds: 0.1,
        maxEvents: null
    )
    ->persistent()
    ->register();

Http::sse("http://localhost:8080/sse", 
    onEvent: function ($event) {
        $data = json_decode($event->data, true);
        echo "[" . date('H:i:s') . "] Event {$data['index']} - ID: {$event->id}\n";
    }
);

Loop::run();
echo "Done\n";