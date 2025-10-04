<?php

use Hibla\Http\Http;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

require 'vendor/autoload.php';

Http::sseDataFormat("json")->sse("https://stream.wikimedia.org/v2/stream/recentchange", function (array $data) {
    echo $data["title"] . PHP_EOL;
});
