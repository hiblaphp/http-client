<?php

use Hibla\Http\Http;
use function Hibla\await;
use function Hibla\Http\fetch;

require 'vendor/autoload.php';

Http::startTesting();

Http::mock()
    ->url('https://api.example.com/events')
    ->respondWithSSE([
        ['data' => json_encode(['type' => 'welcome', 'message' => 'Connected'])],
        ['data' => json_encode(['type' => 'update', 'value' => 42]), 'id' => '1'],
        ['event' => 'notification', 'data' => 'New message'],
    ])
    ->register();

$promise = Http::request()
    ->sseDataFormat('object')
    ->sse(
        'https://api.example.com/events',
        onEvent: function ($event) {
            print_r($event);
        }
    );

$response = await($promise);
