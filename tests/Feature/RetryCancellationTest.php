<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Http;
use Tests\Fixtures\HttpBin;

test('it cancels a real network request during retries', function () {
    HttpBin::skipIfUnreachable();

    $promise = Http::request()
        ->retry(maxRetries: 5, baseDelay: 1.0)
        ->get(HttpBin::url('/status/503'))
    ;

    Loop::addTimer(1.5, function () use ($promise) {
        $promise->cancel();
    });

    $exceptionThrown = false;

    try {
        $promise->wait();
    } catch (Throwable $e) {
        $exceptionThrown = true;
    }

    expect($promise->isCancelled())->toBeTrue();
    expect($exceptionThrown)->toBeTrue();
});
