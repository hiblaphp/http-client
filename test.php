<?php

use Hibla\Http\Http;
use function Hibla\await;

require 'vendor/autoload.php';

// Capture the handler so you can inspect requests later
$handler = Http::startTesting();

Http::mock()
    ->url("https://jsonplaceholder.typicode.com/todos/1")
    ->json([
        "userId" => 1,
        "id" => 1,
        "title" => "test",
        "completed" => false
    ])
    ->persistent()
    ->register();

$startTime = microtime(true);
$response = await(
    Http::request()
        ->withHeader('Cache-Control', 'no-cache')
        ->withHeader("Test", "test")
        ->get("https://jsonplaceholder.typicode.com/todos/1")
);

echo "=== RESPONSE (from server) ===\n";
echo $response->getBody() . PHP_EOL;
echo "\nResponse Headers:\n";
print_r($response->getHeaders());

$endTime = microtime(true);
$executionTime = $endTime - $startTime;
echo "\nExecution time: $executionTime seconds\n";

// NOW show the REQUEST headers you sent
echo "\n=== REQUEST HEADERS (what you sent) ===\n";
$lastRequest = $handler->getLastRequest();
if ($lastRequest) {
    foreach ($lastRequest->getHeaders() as $name => $value) {
        $displayValue = is_array($value) ? implode(', ', $value) : $value;
        echo "$name: $displayValue\n";
    }
}