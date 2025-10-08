<?php

use Hibla\HttpClient\RetryConfig;

test('it calculates exponential backoff delay correctly', function () {
    $config = new RetryConfig(
        maxRetries: 5,
        baseDelay: 1.0,
        backoffMultiplier: 2.0,
        jitter: false
    );

    expect($config->getDelay(1))->toBe(1.0);
    expect($config->getDelay(2))->toBe(2.0);
    expect($config->getDelay(3))->toBe(4.0);
});

test('it respects the max delay setting', function () {
    $config = new RetryConfig(
        baseDelay: 1.0,
        maxDelay: 5.0,
        backoffMultiplier: 3.0,
        jitter: false
    );

    expect($config->getDelay(1))->toBe(1.0);
    expect($config->getDelay(2))->toBe(3.0);
    expect($config->getDelay(3))->toBe(5.0);
    expect($config->getDelay(4))->toBe(5.0);
});

test('isRetryableError identifies retryable exceptions', function () {
    $config = new RetryConfig(
        retryableExceptions: ['Connection timed out', 'connection failed']
    );

    expect($config->isRetryableError(new Exception('A connection failed unexpectedly.')))->toBeTrue();
    expect($config->isRetryableError(new Exception('Error: Connection timed out.')))->toBeTrue();
    expect($config->isRetryableError(new Exception('An unknown error occurred.')))->toBeFalse();
});
