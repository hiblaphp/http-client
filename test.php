<?php

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Request;
use Hibla\HttpClient\Testing\TestingHttpHandler;
use Hibla\HttpClient\Uri;

require 'vendor/autoload.php';

$testHandler = new TestingHttpHandler();
$response = Http::request()
    ->get('https://httpbin.org/get')
    ->await();

$testHandler->dumpLastRequest();
