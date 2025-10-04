<?php

use Hibla\Http\Http;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

require 'vendor/autoload.php';

Http::startTesting()->withGlobalRandomDelay(0.2, 0.5);

Http::mock("*")
    ->url("*")
    ->respondJson(["success" => true])
    ->persistent()
    ->register();

function testHttpMock(): PromiseInterface
{
    return async(function () {
        $promises = [];
        for ($i = 0; $i < 100; $i++) {
            $promises[] = Http::get("https://test.com/$i");
        }
        $start = microtime(true);
        $responses = await(Promise::all($promises));
        $end = microtime(true);
        $time = $end - $start;
        echo "Time taken: $time seconds\n";
        foreach ($responses as $response)
        {
            echo $response->status() . "\n";
        }
    });
}

testHttpMock();
