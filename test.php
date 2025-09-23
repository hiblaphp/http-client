<?php

use Hibla\EventLoop\EventLoop;
use Hibla\Http\Http;
use Hibla\Http\SSE\SSEEvent;
use Hibla\Promise\Promise;

require __DIR__ . '/vendor/autoload.php';

$count = 0;
$shouldStop = false;

await(fetch("https://stream.wikimedia.org/v2/stream/recentchange", [
    "sse" => true,
    "headers" => [
        "User-Agent" => "Hibla/1.0"
    ],
    "reconnect" => [
        "enabled" => true,
        "max_attempts" => 5,
        "initial_delay" => 2.0,
        "on_reconnect" => function ($attempt) {
            echo "🔄 [RECONNECT] Attempt $attempt - " . date('H:i:s') . "\n";
        }
    ],
    "on_event" => function (SSEEvent $event) use (&$count, &$shouldStop) {
        if ($shouldStop) return;

        $data = json_decode($event->data, true);
        $count++;

        echo "✅ [EVENT] #{$count}: " . $data['title'] . " - " . date('H:i:s') . "\n";

        if ($count >= 20) {
            $shouldStop = true;
            echo "🛑 Reached 20 events, stopping...\n";
            EventLoop::getInstance()->stop();
        }
    },
]));

echo "Script finished after processing $count events." . PHP_EOL;
