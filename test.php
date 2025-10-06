<?php

use Hibla\Http\Http;
use Hibla\Http\SSE\SSEEvent;

require 'vendor/autoload.php';

Http::startTesting();

Http::mock()
    ->url("*")
    ->sseFailUntilAttempt(3)
    ->sseInfiniteStream(fn($id) => [
        "id" => $id,
        "event" => "test",
        "data" => json_encode(['id' => $id, 'event' => 'test', 'data' => 'test']),
    ], 0.3, 5)
    ->persistent()
    ->register();

Http::sseReconnect(maxAttempts: 1, onReconnect: function () {
    echo "Reconnected" . PHP_EOL;
})->sse("https://test.coms", function (SSEEvent $event) {
    $data = json_decode($event->data, true);
    print_r($data);
});
