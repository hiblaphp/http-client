<?php

use Hibla\HttpClient\SSE\SSEConnectionState;
use Hibla\HttpClient\SSE\SSEReconnectConfig;

test('tracks attempt count', function () {
    $config = new SSEReconnectConfig();
    $state = new SSEConnectionState('https://example.com', [], $config);

    expect($state->getAttemptCount())->toBe(0);

    $state->incrementAttempt();
    expect($state->getAttemptCount())->toBe(1);

    $state->incrementAttempt();
    expect($state->getAttemptCount())->toBe(2);
});

test('stores last event ID', function () {
    $config = new SSEReconnectConfig();
    $state = new SSEConnectionState('https://example.com', [], $config);

    expect($state->getLastEventId())->toBeNull();

    $state->setLastEventId('event-123');
    expect($state->getLastEventId())->toBe('event-123');
});

test('calculates exponential backoff delay', function () {
    $config = new SSEReconnectConfig(
        initialDelay: 1.0,
        backoffMultiplier: 2.0,
        jitter: false
    );
    $state = new SSEConnectionState('https://example.com', [], $config);

    $state->incrementAttempt(); // Attempt 1
    expect($state->getReconnectDelay())->toBe(1.0);

    $state->incrementAttempt(); // Attempt 2
    expect($state->getReconnectDelay())->toBe(2.0);

    $state->incrementAttempt(); // Attempt 3
    expect($state->getReconnectDelay())->toBe(4.0);
});

test('respects server retry interval', function () {
    $config = new SSEReconnectConfig(initialDelay: 1.0);
    $state = new SSEConnectionState('https://example.com', [], $config);

    $state->setRetryInterval(5000); // 5 seconds in milliseconds
    $state->incrementAttempt();

    expect($state->getReconnectDelay())->toBe(5.0);
});

test('determines if error is retryable', function () {
    $config = new SSEReconnectConfig(
        maxAttempts: 3,
        retryableErrors: ['Connection refused']
    );
    $state = new SSEConnectionState('https://example.com', [], $config);

    $retryableError = new Exception('Connection refused by server');
    $nonRetryableError = new Exception('Invalid authentication');

    expect($state->shouldReconnect($retryableError))->toBeTrue();
    expect($state->shouldReconnect($nonRetryableError))->toBeFalse();
});

test('stops reconnecting after max attempts', function () {
    $config = new SSEReconnectConfig(maxAttempts: 2);
    $state = new SSEConnectionState('https://example.com', [], $config);

    $error = new Exception('Connection refused');

    $state->incrementAttempt();
    expect($state->shouldReconnect($error))->toBeTrue();

    $state->incrementAttempt();
    expect($state->shouldReconnect($error))->toBeFalse();
});
