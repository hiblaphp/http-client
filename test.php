<?php

use Hibla\Http\Http;
use Hibla\EventLoop\EventLoop;

require 'vendor/autoload.php';

Http::startTesting()->withUnstableNetwork();

Http::mock("GET")->url("https://example.com")
    ->respondJson(["sucess" => true])
    ->persistent()
    ->register();

$response = await(Http::get("https://example.com"));
echo $response->getBody()->getContents();



