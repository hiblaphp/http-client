<?php

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\Promise\CancellablePromise;

it('emits SSE events immediately', function () {
    $emitter = createImmediateSSEEmitter();
    $promise = new CancellablePromise();

    $events = [
        ['data' => 'Hello', 'event' => 'message'],
        ['data' => 'World', 'event' => 'update'],
    ];

    $mock = createMockedSSERequest($events);

    $receivedEvents = [];
    $onEvent = function (SSEEvent $event) use (&$receivedEvents) {
        $receivedEvents[] = $event;
    };

    $lastEventId = null;
    $retryInterval = null;

    $emitter->emit($promise, $mock, $onEvent, $lastEventId, $retryInterval);

    expect($receivedEvents)->toHaveCount(2)
        ->and($receivedEvents[0]->data)->toBe('Hello')
        ->and($receivedEvents[0]->event)->toBe('message')
        ->and($receivedEvents[1]->data)->toBe('World')
        ->and($receivedEvents[1]->event)->toBe('update');
});

it('resolves promise with SSEResponse', function () {
    $emitter = createImmediateSSEEmitter();
    $promise = new CancellablePromise();
    $mock = createMockedSSERequest();

    $lastEventId = null;
    $retryInterval = null;
    $emitter->emit($promise, $mock, null, $lastEventId, $retryInterval);

    $response = $promise->await();
    expect($response)->toBeInstanceOf(SSEResponse::class);
});

it('updates lastEventId when event has id', function () {
    $emitter = createImmediateSSEEmitter();
    $promise = new CancellablePromise();

    $events = [
        ['data' => 'First', 'id' => '123'],
        ['data' => 'Second', 'id' => '456'],
    ];

    $mock = createMockedSSERequest($events);

    $lastEventId = null;
    $retryInterval = null;

    $emitter->emit($promise, $mock, null, $lastEventId, $retryInterval);

    expect($lastEventId)->toBe('456');
});

it('updates retryInterval when event has retry', function () {
    $emitter = createImmediateSSEEmitter();
    $promise = new CancellablePromise();

    $events = [
        ['data' => 'Test', 'retry' => 3000],
    ];

    $mock = createMockedSSERequest($events);

    $lastEventId = null;
    $retryInterval = null;

    $emitter->emit($promise, $mock, null, $lastEventId, $retryInterval);

    expect($retryInterval)->toBe(3000);
});

it('handles empty events array', function () {
    $emitter = createImmediateSSEEmitter();
    $promise = new CancellablePromise();
    $mock = createMockedSSERequest([]);

    $receivedEvents = [];
    $onEvent = function (SSEEvent $event) use (&$receivedEvents) {
        $receivedEvents[] = $event;
    };

    $lastEventId = null;
    $retryInterval = null;

    $emitter->emit($promise, $mock, $onEvent, $lastEventId, $retryInterval);

    expect($receivedEvents)->toBeEmpty();
});

it('does not call onEvent when null', function () {
    $emitter = createImmediateSSEEmitter();
    $promise = new CancellablePromise();

    $events = [
        ['data' => 'Test'],
    ];

    $mock = createMockedSSERequest($events);

    $lastEventId = null;
    $retryInterval = null;

    $emitter->emit($promise, $mock, null, $lastEventId, $retryInterval);

    expect(true)->toBeTrue();
});

it('creates stream with formatted SSE content', function () {
    $emitter = createImmediateSSEEmitter();
    $promise = new CancellablePromise();

    $events = [
        ['data' => 'Line 1', 'event' => 'test'],
        ['data' => 'Line 2'],
    ];

    $mock = createMockedSSERequest($events);

    $lastEventId = null;
    $retryInterval = null;
    $emitter->emit($promise, $mock, null, $lastEventId, $retryInterval);

    $resolvedResponse = $promise->await();

    expect($resolvedResponse)->toBeInstanceOf(SSEResponse::class)
        ->and($resolvedResponse->getStream())->not->toBeNull();
});

it('handles multiple event updates correctly', function () {
    $emitter = createImmediateSSEEmitter();
    $promise = new CancellablePromise();

    $events = [
        ['data' => 'First', 'id' => '1', 'retry' => 1000],
        ['data' => 'Second', 'id' => '2'],
        ['data' => 'Third', 'retry' => 2000],
        ['data' => 'Fourth', 'id' => '4'],
    ];

    $mock = createMockedSSERequest($events);

    $lastEventId = null;
    $retryInterval = null;

    $emitter->emit($promise, $mock, null, $lastEventId, $retryInterval);

    expect($lastEventId)->toBe('4')
        ->and($retryInterval)->toBe(2000);
});

it('passes correct status code and headers to SSEResponse', function () {
    $emitter = createImmediateSSEEmitter();
    $promise = new CancellablePromise();

    $headers = ['Content-Type' => 'text/event-stream', 'X-Custom' => 'value'];
    $mock = createMockedSSERequest([], 201, $headers);

    $lastEventId = null;
    $retryInterval = null;
    $emitter->emit($promise, $mock, null, $lastEventId, $retryInterval);

    $resolvedResponse = $promise->await();

    expect($resolvedResponse)->toBeInstanceOf(SSEResponse::class)
        ->and($resolvedResponse->getStatusCode())->toBe(201)
        ->and($resolvedResponse->getHeader('Content-Type'))->toBe(['text/event-stream'])
        ->and($resolvedResponse->getHeader('X-Custom'))->toBe(['value']);
});

it('handles events with only data field', function () {
    $emitter = createImmediateSSEEmitter();
    $promise = new CancellablePromise();

    $events = [
        ['data' => 'Simple event'],
    ];

    $mock = createMockedSSERequest($events);

    $receivedEvents = [];
    $onEvent = function (SSEEvent $event) use (&$receivedEvents) {
        $receivedEvents[] = $event;
    };

    $lastEventId = null;
    $retryInterval = null;

    $emitter->emit($promise, $mock, $onEvent, $lastEventId, $retryInterval);

    expect($receivedEvents)->toHaveCount(1)
        ->and($receivedEvents[0]->data)->toBe('Simple event')
        ->and($receivedEvents[0]->event)->toBeNull()
        ->and($receivedEvents[0]->id)->toBeNull()
        ->and($receivedEvents[0]->retry)->toBeNull();
});

it('preserves lastEventId when subsequent events have no id', function () {
    $emitter = createImmediateSSEEmitter();
    $promise = new CancellablePromise();

    $events = [
        ['data' => 'First', 'id' => '123'],
        ['data' => 'Second'],
        ['data' => 'Third'],
    ];

    $mock = createMockedSSERequest($events);

    $lastEventId = null;
    $retryInterval = null;

    $emitter->emit($promise, $mock, null, $lastEventId, $retryInterval);

    expect($lastEventId)->toBe('123');
});
