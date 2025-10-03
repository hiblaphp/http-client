<?php

use Hibla\Http\Http;

require 'vendor/autoload.php';

Http::startTesting();

Http::mock()
    ->url('*')
    ->respondJson(["success" => true])
    ->persistent()
    ->register();

try {
    $response = await(Http::get("https://test.com"));
    Http::assertRequestMade("GET", "https://test.com");
    echo "Test passed" . PHP_EOL;
} catch (Throwable $e) {
    echo $e->getMessage() . PHP_EOL;
}

Http::dumpLastRequest();
