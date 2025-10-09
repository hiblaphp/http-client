<?php

use Hibla\Promise\Promise;

use function Hibla\delay;

require 'vendor/autoload.php';

function task1()
{
    return async(fn() => await(delay(1)));
}

function task2()
{
    return async(fn() => await(delay(2)));
}

$startTime = microtime(true);
await(Promise::all([task1(), task2()]));
$endTime = microtime(true);
echo 'Total time: ' . ($endTime - $startTime) . ' seconds';
