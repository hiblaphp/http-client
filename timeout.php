<?php

use Hibla\HttpClient\Http;

require 'vendor/autoload.php';

$response = Http::timeout(1)->get("httpbin.org/delay/5")->await();

echo $response->body();
