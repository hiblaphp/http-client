<?php

use Hibla\Http\Http;
use Hibla\Http\Request;

require 'vendor/autoload.php';

$baseClient = Http::interceptRequest(fn(Request $request) => $request->withHeader('X-Test', 'test'));
$response = $baseClient->get("https://httpbin.org/headers")->await();
echo $response->getBody()->getContents();
