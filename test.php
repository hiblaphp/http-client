<?php

use Hibla\Http\Http;
use Hibla\EventLoop\EventLoop;

require 'vendor/autoload.php';

Http::startTesting();

Http::mock('GET')
    ->url('https://api.example.com/complex')
    ->sseFailUntilAttempt(4)
    ->respondWithSSE([
        ['id' => 1, 'data' => 'First message'],
        ['id' => 2, 'data' => 'Second message'],
        ['id' => 3, 'data' => 'Third message'],
    ])
    ->persistent()
    ->register();

$response = await(Http::sseReconnect()->sseDataFormat("object")
    ->sse("https://api.example.com/complex", function ($data) {
        print_r($data);
    }));
