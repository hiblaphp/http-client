<?php

use Hibla\Http\Http;

require __DIR__ . '/vendor/autoload.php';

$request = Http::request()
    ->stream('https://httpbin.org/stream/10', function ($data) {
        echo $data;
    })
    ->await();
