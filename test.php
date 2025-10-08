<?php

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Request;
use Hibla\HttpClient\Uri;

require 'vendor/autoload.php';

Http::startTesting()
    ->mock()
    ->url("*")
    ->register();

$response = Http::request()
    ->interceptRequest(function (Request $request) {
        return $request->withUri(new Uri('https://laravel.com/docs/12.x/validationsss'));
    })
    ->get('https://laravel.com/docs/12.x')
    ->await();

Http::dumpLastRequest();
