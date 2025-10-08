<?php

use Hibla\HttpClient\SSE\SSEReconnectConfig;

describe('SSEReconnectConfig', function () {
    it('creates config with default values', function () {
        $config = new SSEReconnectConfig();
        
        expect($config->enabled)->toBeTrue()
            ->and($config->maxAttempts)->toBe(10)
            ->and($config->initialDelay)->toBe(1.0)
            ->and($config->maxDelay)->toBe(30.0)
            ->and($config->backoffMultiplier)->toBe(2.0)
            ->and($config->jitter)->toBeTrue()
            ->and($config->retryableErrors)->toBeArray()
            ->and($config->onReconnect)->toBeNull()
            ->and($config->shouldReconnect)->toBeNull();
    });

    it('creates config with custom values', function () {
        $config = new SSEReconnectConfig(
            enabled: false,
            maxAttempts: 5,
            initialDelay: 2.0,
            maxDelay: 60.0,
            backoffMultiplier: 1.5,
            jitter: false
        );
        
        expect($config->enabled)->toBeFalse()
            ->and($config->maxAttempts)->toBe(5)
            ->and($config->initialDelay)->toBe(2.0)
            ->and($config->maxDelay)->toBe(60.0)
            ->and($config->backoffMultiplier)->toBe(1.5)
            ->and($config->jitter)->toBeFalse();
    });

    it('calculates exponential backoff delay', function () {
        $config = new SSEReconnectConfig(
            initialDelay: 1.0,
            backoffMultiplier: 2.0,
            jitter: false
        );
        
        expect($config->calculateDelay(1))->toBe(1.0)
            ->and($config->calculateDelay(2))->toBe(2.0)
            ->and($config->calculateDelay(3))->toBe(4.0)
            ->and($config->calculateDelay(4))->toBe(8.0)
            ->and($config->calculateDelay(5))->toBe(16.0);
    });

    it('respects max delay cap', function () {
        $config = new SSEReconnectConfig(
            initialDelay: 1.0,
            maxDelay: 5.0,
            backoffMultiplier: 2.0,
            jitter: false
        );
        
        expect($config->calculateDelay(10))->toBe(5.0)
            ->and($config->calculateDelay(100))->toBe(5.0);
    });

    it('applies jitter to delay', function () {
        $config = new SSEReconnectConfig(
            initialDelay: 10.0,
            jitter: true,
            backoffMultiplier: 1.0
        );
        
        $delays = [];
        for ($i = 0; $i < 10; $i++) {
            $delays[] = $config->calculateDelay(1);
        }
        
        // All delays should be between 5.0 and 10.0
        foreach ($delays as $delay) {
            expect($delay)->toBeGreaterThan(5.0)
                ->and($delay)->toBeLessThanOrEqual(10.0);
        }
        
        // They should not all be the same (randomness)
        $uniqueDelays = array_unique(array_map(fn($d) => round($d, 2), $delays));
        expect(count($uniqueDelays))->toBeGreaterThan(1);
    });

    it('calculates delay without jitter consistently', function () {
        $config = new SSEReconnectConfig(
            initialDelay: 2.0,
            backoffMultiplier: 2.0,
            jitter: false
        );
        
        $delay1 = $config->calculateDelay(3);
        $delay2 = $config->calculateDelay(3);
        
        expect($delay1)->toBe($delay2)->toBe(8.0);
    });

    it('includes default retryable errors', function () {
        $config = new SSEReconnectConfig();
        
        expect($config->retryableErrors)->toContain('Connection refused')
            ->and($config->retryableErrors)->toContain('Connection reset')
            ->and($config->retryableErrors)->toContain('Connection timed out')
            ->and($config->retryableErrors)->toContain('Could not resolve host')
            ->and($config->retryableErrors)->toContain('Network is unreachable');
    });

    it('identifies retryable errors by message', function () {
        $config = new SSEReconnectConfig();
        
        $retryable = new Exception('Connection refused by server');
        $notRetryable = new Exception('Authentication failed');
        
        expect($config->isRetryableError($retryable))->toBeTrue()
            ->and($config->isRetryableError($notRetryable))->toBeFalse();
    });

    it('checks all default retryable errors', function () {
        $config = new SSEReconnectConfig();
        
        $errors = [
            new Exception('Connection refused'),
            new Exception('Connection reset by peer'),
            new Exception('Connection timed out after 30s'),
            new Exception('Could not resolve host: example.com'),
            new Exception('Network is unreachable'),
            new Exception('Operation timed out'),
        ];
        
        foreach ($errors as $error) {
            expect($config->isRetryableError($error))->toBeTrue();
        }
    });

    it('uses custom retryable errors list', function () {
        $config = new SSEReconnectConfig(
            retryableErrors: ['Custom error', 'Another error']
        );
        
        $shouldRetry = new Exception('Custom error occurred');
        $shouldNotRetry = new Exception('Connection refused');
        
        expect($config->isRetryableError($shouldRetry))->toBeTrue()
            ->and($config->isRetryableError($shouldNotRetry))->toBeFalse();
    });

    it('uses custom shouldReconnect callback', function () {
        $config = new SSEReconnectConfig(
            shouldReconnect: fn(Exception $e) => str_contains($e->getMessage(), 'retry')
        );
        
        $shouldRetry = new Exception('Please retry this operation');
        $shouldNotRetry = new Exception('Fatal error occurred');
        
        expect($config->isRetryableError($shouldRetry))->toBeTrue()
            ->and($config->isRetryableError($shouldNotRetry))->toBeFalse();
    });

    it('prioritizes custom callback over default errors', function () {
        $config = new SSEReconnectConfig(
            shouldReconnect: fn(Exception $e) => $e->getCode() === 503
        );
        
        $retryByCode = new Exception('Any message', 503);
        $defaultRetryable = new Exception('Connection refused', 0);
        
        expect($config->isRetryableError($retryByCode))->toBeTrue()
            ->and($config->isRetryableError($defaultRetryable))->toBeFalse();
    });

    it('accepts onReconnect callback', function () {
        $callback = function () {
            return 'reconnecting';
        };
        
        $config = new SSEReconnectConfig(onReconnect: $callback);
        
        expect($config->onReconnect)->toBe($callback);
    });

    it('handles zero initial delay', function () {
        $config = new SSEReconnectConfig(
            initialDelay: 0.0,
            jitter: false
        );
        
        expect($config->calculateDelay(1))->toBe(0.0)
            ->and($config->calculateDelay(2))->toBe(0.0);
    });

    it('handles backoff multiplier of 1', function () {
        $config = new SSEReconnectConfig(
            initialDelay: 5.0,
            backoffMultiplier: 1.0,
            jitter: false
        );
        
        expect($config->calculateDelay(1))->toBe(5.0)
            ->and($config->calculateDelay(2))->toBe(5.0)
            ->and($config->calculateDelay(10))->toBe(5.0);
    });

    it('handles fractional backoff multiplier', function () {
        $config = new SSEReconnectConfig(
            initialDelay: 10.0,
            backoffMultiplier: 1.5,
            jitter: false
        );
        
        expect($config->calculateDelay(1))->toBe(10.0)
            ->and($config->calculateDelay(2))->toBe(15.0)
            ->and($config->calculateDelay(3))->toBe(22.5);
    });

    it('handles large attempt numbers', function () {
        $config = new SSEReconnectConfig(
            initialDelay: 1.0,
            maxDelay: 30.0,
            backoffMultiplier: 2.0,
            jitter: false
        );
        
        // Should cap at maxDelay
        expect($config->calculateDelay(100))->toBe(30.0)
            ->and($config->calculateDelay(1000))->toBe(30.0);
    });

    it('is case-sensitive for error matching', function () {
        $config = new SSEReconnectConfig(
            retryableErrors: ['connection refused']
        );
        
        $lowercase = new Exception('connection refused');
        $uppercase = new Exception('Connection Refused');
        
        expect($config->isRetryableError($lowercase))->toBeTrue()
            ->and($config->isRetryableError($uppercase))->toBeFalse();
    });

    it('matches partial error messages', function () {
        $config = new SSEReconnectConfig(
            retryableErrors: ['timeout']
        );
        
        $error1 = new Exception('Connection timeout occurred');
        $error2 = new Exception('Operation timed out');
        $error3 = new Exception('timeout');
        
        expect($config->isRetryableError($error1))->toBeTrue()
            ->and($config->isRetryableError($error2))->toBeFalse()
            ->and($config->isRetryableError($error3))->toBeTrue();
    });

    it('handles empty retryable errors list', function () {
        $config = new SSEReconnectConfig(retryableErrors: []);
        
        $error = new Exception('Connection refused');
        
        expect($config->isRetryableError($error))->toBeFalse();
    });
});