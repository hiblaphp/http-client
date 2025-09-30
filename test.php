<?php

use Hibla\Http\Http;
use function Hibla\await;
use function Hibla\Http\fetch;

require 'vendor/autoload.php';

Http::startTesting();

Http::mock()
    ->url('https://api.example.com/users')
    ->expectJson(['name' => 'John'])     
    ->respondJson(['id' => 1])
    ->register();

$response = await(
    fetch('https://api.example.com/users', [
        'method' => 'POST',
        'json' => ['name' => 'John'],
    ])
);

echo $response->getBody();
