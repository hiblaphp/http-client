<?php

declare(strict_types=1);

namespace Tests\Handlers\Curl;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Handlers\Curl\RetryHandler;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Promise;
use Tests\Fixtures\HttpBin;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
    Loop::reset();
});

afterEach(function () {
    Loop::reset();
});

it('resolves with a Response on a successful request', function () {
    $handler = new RetryHandler();
    $promise = $handler->execute(
        HttpBin::url('/get'),
        [CURLOPT_URL => HttpBin::url('/get'), CURLOPT_RETURNTRANSFER => true],
        new RetryConfig()
    );

    $response = null;
    $error    = null;

    $promise->then(function ($res) use (&$response) {
        $response = $res;
        Loop::stop();
    })->catch(function ($err) use (&$error) {
        $error = $err;
        Loop::stop();
    });

    Loop::run();

    expect($error)->toBeNull();
    expect($response)->toBeInstanceOf(Response::class);
    expect($response->status())->toBe(200);
    expect($response->getBody())->not->toBeEmpty();
});

it('returns a Promise instance', function () {
    $handler = new RetryHandler();
    $promise = $handler->execute(
        HttpBin::url('/get'),
        [CURLOPT_URL => HttpBin::url('/get')],
        new RetryConfig()
    );

    expect($promise)->toBeInstanceOf(Promise::class);

    $promise->cancel();
});

it('normalizes response headers', function () {
    $handler = new RetryHandler();
    $promise = $handler->execute(
        HttpBin::url('/get'),
        [CURLOPT_URL => HttpBin::url('/get'), CURLOPT_RETURNTRANSFER => true],
        new RetryConfig()
    );

    $response = null;
    $promise->then(function ($res) use (&$response) {
        $response = $res;
        Loop::stop();
    })->catch(function ($err) {
        Loop::stop();
    });

    Loop::run();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->getHeader('content-type'))->not->toBeNull();
});

it('sets the HTTP version on the response', function () {
    $handler = new RetryHandler();
    $promise = $handler->execute(
        HttpBin::url('/get'),
        [CURLOPT_URL => HttpBin::url('/get'), CURLOPT_RETURNTRANSFER => true],
        new RetryConfig()
    );

    $response = null;
    $promise->then(function ($res) use (&$response) {
        $response = $res;
        Loop::stop();
    })->catch(function ($err) {
        Loop::stop();
    });

    Loop::run();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->getHttpVersion())->not->toBeNull();
});

it('strips internal _cookie_jar key before executing', function () {
    $handler = new RetryHandler();
    $promise = $handler->execute(
        HttpBin::url('/get'),
        [
            CURLOPT_URL            => HttpBin::url('/get'),
            CURLOPT_RETURNTRANSFER => true,
            '_cookie_jar'          => 'should-be-stripped',
        ],
        new RetryConfig()
    );

    $response = null;
    $error    = null;

    $promise->then(function ($res) use (&$response) {
        $response = $res;
        Loop::stop();
    })->catch(function ($err) use (&$error) {
        $error = $err;
        Loop::stop();
    });

    Loop::run();

    expect($error)->toBeNull();
    expect($response)->toBeInstanceOf(Response::class);
    expect($response->status())->toBe(200);
});

it('strips internal _tmp_files key before executing', function () {
    $handler = new RetryHandler();
    $promise = $handler->execute(
        HttpBin::url('/get'),
        [
            CURLOPT_URL            => HttpBin::url('/get'),
            CURLOPT_RETURNTRANSFER => true,
            '_tmp_files'           => ['/tmp/should-be-stripped'],
        ],
        new RetryConfig()
    );

    $response = null;
    $error    = null;

    $promise->then(function ($res) use (&$response) {
        $response = $res;
        Loop::stop();
    })->catch(function ($err) use (&$error) {
        $error = $err;
        Loop::stop();
    });

    Loop::run();

    expect($error)->toBeNull();
    expect($response)->toBeInstanceOf(Response::class);
    expect($response->status())->toBe(200);
});

it('rejects with NetworkException when the target is unreachable', function () {
    $handler = new RetryHandler();
    $promise = $handler->execute(
        'http://127.0.0.1:19999',
        [CURLOPT_URL => 'http://127.0.0.1:19999', CURLOPT_CONNECTTIMEOUT => 1],
        new RetryConfig(maxRetries: 0)
    );

    $error = null;
    $promise->catch(function ($err) use (&$error) {
        $error = $err;
        Loop::stop();
    });

    Loop::run();

    expect($error)->toBeInstanceOf(NetworkException::class);
});

it('retries the configured number of times before rejecting', function () {
    $handler = new RetryHandler();
    $promise = $handler->execute(
        'http://127.0.0.1:19999',
        [CURLOPT_URL => 'http://127.0.0.1:19999', CURLOPT_CONNECTTIMEOUT => 1],
        new RetryConfig(maxRetries: 2, baseDelay: 0.01)
    );

    $error = null;
    $promise->catch(function ($err) use (&$error) {
        $error = $err;
        Loop::stop();
    });

    Loop::run();

    expect($error)->toBeInstanceOf(NetworkException::class);
    expect($error->getMessage())->toContain('3 attempts');
});

it('does not retry non-retryable status codes', function () {
    $handler = new RetryHandler();
    $promise = $handler->execute(
        HttpBin::url('/status/404'),
        [CURLOPT_URL => HttpBin::url('/status/404'), CURLOPT_RETURNTRANSFER => true],
        new RetryConfig(maxRetries: 3, retryableStatusCodes: [500])
    );

    $response = null;
    $promise->then(function ($res) use (&$response) {
        $response = $res;
        Loop::stop();
    })->catch(function ($err) {
        Loop::stop();
    });

    Loop::run();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->status())->toBe(404);
});

it('handles cancellation properly', function () {
    $handler = new RetryHandler();
    $promise = $handler->execute(
        HttpBin::url('/get'),
        [CURLOPT_URL => HttpBin::url('/get')],
        new RetryConfig()
    );

    expect($promise)->toBeInstanceOf(Promise::class);

    $promise->cancel();

    expect($promise->isCancelled())->toBeTrue();
});

it('does not resolve cancelled promises', function () {
    $handler = new RetryHandler();
    $promise = $handler->execute(
        HttpBin::url('/get'),
        [CURLOPT_URL => HttpBin::url('/get'), CURLOPT_RETURNTRANSFER => true],
        new RetryConfig()
    );

    $resolved = false;
    $promise->then(function () use (&$resolved) {
        $resolved = true;
        Loop::stop();
    });

    $promise->cancel();

    Loop::addTimer(0.1, function () {
        Loop::stop();
    });

    Loop::run();

    expect($resolved)->toBeFalse();
});