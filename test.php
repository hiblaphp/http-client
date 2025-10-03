<?php

use Hibla\Http\Http;
use Hibla\Promise\Promise;

require 'vendor/autoload.php';

Http::startTesting()->withGlobalRandomDelay(0.3, 0.5);

Http::mock()->url("*")
    ->respondJson(["message" => "success"])
    ->persistent()
    ->register();


for ($i = 0; $i < 10; $i++) {
    $startTime = microtime(true);
    await(Promise::all([
    Http::get("https://jsonplaceholder.typicode.com/posts"),
    Http::get("https://jsonplaceholder.typicode.com/posts"),
    Http::get("https://jsonplaceholder.typicode.com/posts"),
    Http::get("https://jsonplaceholder.typicode.com/posts"),
    Http::get("https://jsonplaceholder.typicode.com/posts"),
]));

    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    echo "Execution time: " . $executionTime . " seconds\n";
}
