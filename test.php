<?php

use Hibla\Http\Http;

require 'vendor/autoload.php';

Http::startTesting();

Http::mock()
    ->url("https://test.com")
    ->persistent()
    ->register();

$response = await (Http::request()->get("https://test.coms"));
echo $response->status();

