<?php

use Hibla\EventLoop\EventLoop;
use Hibla\HttpClient\Handlers\RequestExecutorHandler;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\Promise\CancellablePromise;

beforeEach(function () {
    EventLoop::reset();
});

afterEach(function () {
    EventLoop::reset();
});


it('executes a basic HTTP request successfully', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute('https://jsonplaceholder.typicode.com/posts/1', [
        CURLOPT_URL => 'https://jsonplaceholder.typicode.com/posts/1',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = null;
    $error = null;

    $promise->then(function ($res) use (&$response) {
        $response = $res;
        EventLoop::getInstance()->stop();
    })->catch(function ($err) use (&$error) {
        $error = $err;
        EventLoop::getInstance()->stop();
    });

    EventLoop::getInstance()->run();

    expect($error)->toBeNull();
    expect($response)->toBeInstanceOf(Response::class);
    expect($response->status())->toBe(200);
    expect($response->getBody())->not->toBeEmpty();
})->skipOnCI();

it('rejects promise on network error', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute('https://invalid-domain-that-does-not-exist-12345.com', [
        CURLOPT_URL => 'https://invalid-domain-that-does-not-exist-12345.com',
    ]);

    $error = null;
    $promise->catch(function ($err) use (&$error) {
        $error = $err;
        EventLoop::getInstance()->stop();
    });

    EventLoop::getInstance()->run();

    expect($error)->toBeInstanceOf(NetworkException::class);
})->skipOnCI();

it('handles cancellation properly', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute('https://jsonplaceholder.typicode.com/posts/1', [
        CURLOPT_URL => 'https://jsonplaceholder.typicode.com/posts/1',
    ]);

    expect($promise)->toBeInstanceOf(CancellablePromise::class);

    $promise->cancel();

    expect($promise->isCancelled())->toBeTrue();
})->skipOnCI();

it('normalizes headers correctly', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute('https://jsonplaceholder.typicode.com/posts/1', [
        CURLOPT_URL => 'https://jsonplaceholder.typicode.com/posts/1',
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = null;
    $promise->then(function ($res) use (&$response) {
        $response = $res;
        EventLoop::getInstance()->stop();
    })->catch(function ($err) {
        EventLoop::getInstance()->stop();
    });

    EventLoop::getInstance()->run();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->getHeader('content-type'))->not->toBeNull();
})->skipOnCI();

it('filters out curl-only options before execution', function () {
    $handler = new RequestExecutorHandler();

    $promise = $handler->execute('https://jsonplaceholder.typicode.com/posts/1', [
        CURLOPT_URL => 'https://jsonplaceholder.typicode.com/posts/1',
        CURLOPT_RETURNTRANSFER => true,
        '_cookie_jar' => 'should-be-filtered',
    ]);

    $response = null;
    $error = null;
    $promise->then(function ($res) use (&$response) {
        $response = $res;
        EventLoop::getInstance()->stop();
    })->catch(function ($err) use (&$error) {
        $error = $err;
        EventLoop::getInstance()->stop();
    });

    EventLoop::getInstance()->run();

    expect($error)->toBeNull();
    expect($response)->toBeInstanceOf(Response::class);
    expect($response->status())->toBe(200);
})->skipOnCI();

it('sets HTTP version on response when provided', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute('https://jsonplaceholder.typicode.com/posts/1', [
        CURLOPT_URL => 'https://jsonplaceholder.typicode.com/posts/1',
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = null;
    $promise->then(function ($res) use (&$response) {
        $response = $res;
        EventLoop::getInstance()->stop();
    })->catch(function ($err) {
        EventLoop::getInstance()->stop();
    });

    EventLoop::getInstance()->run();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->getHttpVersion())->not->toBeNull();
})->skipOnCI();

it('does not resolve cancelled promises', function () {
    $handler = new RequestExecutorHandler();
    $promise = $handler->execute('https://jsonplaceholder.typicode.com/posts/1', [
        CURLOPT_URL => 'https://jsonplaceholder.typicode.com/posts/1',
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $resolved = false;
    $promise->then(function () use (&$resolved) {
        $resolved = true;
        EventLoop::getInstance()->stop();
    });

    $promise->cancel();

    EventLoop::getInstance()->addTimer(0.1, function () {
        EventLoop::getInstance()->stop();
    });

    EventLoop::getInstance()->run();

    expect($resolved)->toBeFalse();
})->skipOnCI();
