<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Handlers\Curl\RequestExecutorHandler;
use Hibla\HttpClient\Response;
use Hibla\Promise\Promise;
use Tests\Fixtures\HttpBin;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
    Loop::reset();
});

afterEach(function () {
    Loop::reset();
});

it('executes a basic HTTP request successfully', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute(HttpBin::url('/get'), [
        CURLOPT_URL            => HttpBin::url('/get'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

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

it('rejects promise on network error', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute('http://127.0.0.1:19999', [
        CURLOPT_URL            => 'http://127.0.0.1:19999',
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);

    $error = null;
    $promise->catch(function ($err) use (&$error) {
        $error = $err;
        Loop::stop();
    });

    Loop::run();

    expect($error)->toBeInstanceOf(NetworkException::class);
});

it('handles cancellation properly', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute(HttpBin::url('/get'), [
        CURLOPT_URL => HttpBin::url('/get'),
    ]);

    expect($promise)->toBeInstanceOf(Promise::class);

    $promise->cancel();

    expect($promise->isCancelled())->toBeTrue();
});

it('normalizes headers correctly', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute(HttpBin::url('/get'), [
        CURLOPT_URL            => HttpBin::url('/get'),
        CURLOPT_RETURNTRANSFER => true,
    ]);

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

it('filters out curl-only options before execution', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute(HttpBin::url('/get'), [
        CURLOPT_URL            => HttpBin::url('/get'),
        CURLOPT_RETURNTRANSFER => true,
        '_cookie_jar'          => 'should-be-filtered',
    ]);

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

it('sets HTTP version on response when provided', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute(HttpBin::url('/get'), [
        CURLOPT_URL            => HttpBin::url('/get'),
        CURLOPT_RETURNTRANSFER => true,
    ]);

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

it('does not resolve cancelled promises', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute(HttpBin::url('/get'), [
        CURLOPT_URL            => HttpBin::url('/get'),
        CURLOPT_RETURNTRANSFER => true,
    ]);

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