<?php

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Response;

use function Hibla\asyncFn;
use function Hibla\await;

require 'vendor/autoload.php';

$client = Http::request()
    ->interceptResponse(asyncFn(function (Response $response) {
        if ($response->status() === 404) {
            throw new \Exception('Not Found:::');
        }
    }));


await($client->get("https://httpbin.org/404"));
