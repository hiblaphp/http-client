<?php

use Hibla\HttpClient\Http;

require "vendor/autoload.php";

Http::startTesting();

Http::mock()->url("*")->register();

$response = await(Http::get("https://example.com"));

echo $response->status();