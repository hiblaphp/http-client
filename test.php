<?php

use Hibla\Http\Exceptions\RequestException;
use Hibla\Http\Http;
use Hibla\Http\Request;
use Hibla\Http\Response;

use function Hibla\await;

require 'vendor/autoload.php';

// class SampleInterceptor
// {
//     public function __invoke(Request $request)
//     {
//         return $request->withHeader("Test", "Test-Value");
//     }
// }

class ShortCircuitInterceptor
{
    public function __invoke(Response $response)
    {
        if ($response->getStatus() >= 400 && $response->getStatus() < 500) {
            throw new RequestException("Client failed by Short-circuited response with status {$response->getStatus()}");
        }

        if ($response->getStatus() >= 500) {
            throw new RequestException("Server failed by Short-circuited response with status {$response->getStatus()}");
        }

        return $response;
    }
}

try {
    $response = await(Http::interceptResponse(new ShortCircuitInterceptor())->get("https://httpbin.org/get"));
    echo $response->getBody()->getContents();
} catch (RequestException $e) {
    echo $e->getMessage();
}
