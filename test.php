<?php

use Hibla\EventLoop\EventLoop;
use Hibla\EventLoop\Loop;
use Hibla\Http\Http;

require 'vendor/autoload.php';

$parseData = function ($data) {
  echo $data["title"] . PHP_EOL;
};
$promise = Http::sseDataFormat()->sse("https://stream.wikimedia.org/v2/stream/recentchange", $parseData);
