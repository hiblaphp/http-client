<?php

use Hibla\Http\Http;
use function Hibla\await;

require 'vendor/autoload.php';

Http::startTesting()->withGlobalRandomDelay(1, 1);

Http::mock("GET")->url("https://example.com")
    ->respondJson(["message" => "Hello, World!"])
    ->failUntilAttempt(3)  
    ->persistent()
    ->register();

try {
    $response = await(
        Http::retry(maxRetries: 2, baseDelay: 1, backoffMultiplier: 2)->get("https://example.com")
    );
    echo $response->getBody() . PHP_EOL;
} catch (Exception $error) {
    echo "Error: " . $error->getMessage() . PHP_EOL;
}