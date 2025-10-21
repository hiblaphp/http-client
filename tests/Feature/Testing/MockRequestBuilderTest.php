<?php

use Hibla\HttpClient\Testing\MockRequestBuilder;
use Hibla\HttpClient\Testing\TestingHttpHandler;

function createBuilder(string $method = 'GET'): MockRequestBuilder
{
    $handler = new TestingHttpHandler();

    return new MockRequestBuilder($handler, $method);
}

test('sets url pattern', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/test');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets response status', function () {
    $builder = createBuilder();
    $builder->respondWithStatus(201);
    $builder->register();

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets response body', function () {
    $builder = createBuilder();
    $builder->respondWith('test body');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets json response', function () {
    $builder = createBuilder();
    $builder->respondJson(['status' => 'success']);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets delay', function () {
    $builder = createBuilder();
    $builder->delay(2.5);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets random delay', function () {
    $builder = createBuilder();
    $builder->randomDelay(1.0, 3.0);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('throws exception for invalid random delay range', function () {
    $builder = createBuilder();
    $builder->randomDelay(5.0, 2.0);
})->throws(InvalidArgumentException::class, 'Minimum delay cannot be greater than maximum delay');

test('sets persistent with random delay', function () {
    $builder = createBuilder();
    $builder->randomPersistentDelay(0.5, 2.0);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets slow response', function () {
    $builder = createBuilder();
    $builder->slowResponse(5.0);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('makes mock persistent', function () {
    $builder = createBuilder();
    $builder->persistent();

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

// BuildsRequestExpectations tests
test('expects header', function () {
    $builder = createBuilder();
    $builder->expectHeader('Authorization', 'Bearer token123');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('expects multiple headers', function () {
    $builder = createBuilder();
    $builder->expectHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ]);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('expects body pattern', function () {
    $builder = createBuilder();
    $builder->expectBody('{"test": "data"}');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('expects json data', function () {
    $builder = createBuilder();
    $builder->expectJson(['key' => 'value']);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('expects cookies', function () {
    $builder = createBuilder();
    $builder->expectCookies(['session' => 'abc123']);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

// BuildsResponseHeaders tests
test('adds response header', function () {
    $builder = createBuilder();
    $builder->respondWithHeader('X-Custom', 'value');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('adds multiple response headers', function () {
    $builder = createBuilder();
    $builder->respondWithHeaders([
        'X-Rate-Limit' => '100',
        'X-Rate-Remaining' => '99',
    ]);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets body chunks for streaming', function () {
    $builder = createBuilder();
    $builder->respondWithChunks(['chunk1', 'chunk2', 'chunk3']);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

// BuildsFailureMocks tests
test('sets failure error', function () {
    $builder = createBuilder();
    $builder->fail('Custom error message');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets timeout', function () {
    $builder = createBuilder();
    $builder->timeout(30.0);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets timeout failure', function () {
    $builder = createBuilder();
    $builder->timeoutFailure(10.0);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets retryable failure', function () {
    $builder = createBuilder();
    $builder->retryableFailure('Network error');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets network error', function () {
    $builder = createBuilder();
    $builder->networkError('connection');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

// BuildsRetrySequences tests
test('fails until specific attempt', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/retry')
        ->failUntilAttempt(3)
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('throws exception for invalid success attempt', function () {
    $builder = createBuilder();
    $builder->failUntilAttempt(0);
})->throws(InvalidArgumentException::class, 'Success attempt must be >= 1');

test('fails with sequence', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/seq')
        ->failWithSequence([
            'Connection failed',
            ['error' => 'Timeout', 'delay' => 0.5],
            ['error' => 'Network error', 'status' => 503],
        ])
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('timeouts until success', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/timeout')
        ->timeoutUntilAttempt(2)
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('status failures until success', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/status')
        ->statusFailuresUntilAttempt(3, 500)
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('mixed failures until success', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/mixed')
        ->mixedFailuresUntilAttempt(4)
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('intermittent failures', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/intermittent')
        ->intermittentFailures([true, false, true, false])
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('slowly improves until attempt', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/improve')
        ->slowlyImproveUntilAttempt(3)
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('rate limited until attempt', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/rate')
        ->rateLimitedUntilAttempt(3)
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('responds with SSE events', function () {
    $builder = createBuilder();
    $builder->respondWithSSE([
        ['event' => 'message', 'data' => 'Hello'],
        ['event' => 'message', 'data' => 'World'],
    ]);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('adds single SSE event', function () {
    $builder = createBuilder();
    $builder->addSSEEvent('Test data', 'message', '1', 3000);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE with keepalive', function () {
    $builder = createBuilder();
    $builder->sseWithKeepalive([
        ['data' => 'event1'],
        ['data' => 'event2'],
    ], 2);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE disconnect after events', function () {
    $builder = createBuilder();
    $builder->sseDisconnectAfter(5, 'Connection reset');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE with retry interval', function () {
    $builder = createBuilder();
    $builder->sseWithRetry([
        ['data' => 'event1'],
    ], 5000);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE with multiple event types', function () {
    $builder = createBuilder();
    $builder->sseMultipleTypes([
        'message' => ['data1', 'data2'],
        'update' => [['status' => 'ok']],
    ]);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE with event IDs', function () {
    $builder = createBuilder();
    $builder->sseWithEventIds([
        ['data' => 'event1', 'id' => '1'],
        ['data' => 'event2', 'id' => '2'],
    ]);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('throws exception for SSE without event IDs', function () {
    $builder = createBuilder();
    $builder->sseWithEventIds([
        ['data' => 'event1'],
    ]);
})->throws(InvalidArgumentException::class, 'All events must have an id field');

test('SSE expects last event ID', function () {
    $builder = createBuilder();
    $builder->sseExpectLastEventId('123', [
        ['data' => 'resume data'],
    ]);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE with retry directive', function () {
    $builder = createBuilder();
    $builder->sseWithRetryDirective(3000, [
        ['data' => 'event1'],
    ]);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE with comments', function () {
    $builder = createBuilder();
    $builder->sseWithComments(
        [['data' => 'event1'], ['data' => 'event2']],
        ['First comment', 'Second comment']
    );

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE heartbeat only', function () {
    $builder = createBuilder();
    $builder->sseHeartbeatOnly(5);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

// BuildsSSERetrySequences tests
test('SSE fails until attempt', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/sse')
        ->sseFailUntilAttempt(3, [
            ['data' => 'success'],
        ])
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE fail with sequence', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/sse')
        ->sseFailWithSequence([
            'Connection failed',
            ['error' => 'Network error', 'delay' => 0.2],
        ])
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE timeout until attempt', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/sse')
        ->sseTimeoutUntilAttempt(2)
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE intermittent failures', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/sse')
        ->sseIntermittentFailures([true, false, true])
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE network errors until attempt', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/sse')
        ->sseNetworkErrorsUntilAttempt(3)
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE slowly improves until attempt', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/sse')
        ->sseSlowlyImproveUntilAttempt(3)
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE drops after events', function () {
    $builder = createBuilder();
    $builder->sseDropAfterEvents([
        ['data' => 'event1'],
        ['data' => 'event2'],
    ], 'Connection lost');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE reconnect from event ID', function () {
    $builder = createBuilder();
    $builder->sseReconnectFromEventId('123', [
        ['data' => 'resumed'],
    ]);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE mixed failures until attempt', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/sse')
        ->sseMixedFailuresUntilAttempt(4)
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE rate limited until attempt', function () {
    $builder = createBuilder();
    $builder->url('https://api.example.com/sse')
        ->sseRateLimitedUntilAttempt(3)
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

// BuildsRealisticSSEMocks tests
test('SSE with periodic events', function () {
    $builder = createBuilder();
    $builder->sseWithPeriodicEvents([
        ['data' => 'event1'],
        ['data' => 'event2'],
    ], 1.0, 0.1);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE with limited events', function () {
    $builder = createBuilder();
    $builder->sseWithLimitedEvents(5, 1.0);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE with limited events and custom generator', function () {
    $builder = createBuilder();
    $builder->sseWithLimitedEvents(3, 1.0, function ($index) {
        return ['data' => "custom_$index", 'id' => (string)$index];
    });

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE infinite stream', function () {
    $builder = createBuilder();
    $builder->sseInfiniteStream(function ($index) {
        return ['data' => "event_$index"];
    }, 1.0, 10);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE periodic then disconnect', function () {
    $builder = createBuilder();
    $builder->ssePeriodicThenDisconnect(3, 1.0, 'Disconnected');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('SSE periodic then disconnect with custom generator', function () {
    $builder = createBuilder();
    $builder->ssePeriodicThenDisconnect(2, 1.0, 'Lost connection', function ($index) {
        return ['data' => "custom_$index"];
    });

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('downloads file', function () {
    $builder = createBuilder();
    $builder->downloadFile('file content', 'test.txt', 'text/plain');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('downloads large file', function () {
    $builder = createBuilder();
    $builder->downloadLargeFile(50, 'large.bin');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets cookies', function () {
    $builder = createBuilder();
    $builder->setCookies([
        'session' => ['value' => 'abc123', 'path' => '/'],
        'user' => ['value' => 'john', 'httpOnly' => true],
    ]);

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('sets single cookie', function () {
    $builder = createBuilder();
    $builder->setCookie('token', 'xyz789', '/', 'example.com', time() + 3600, true, true, 'Strict');

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('chains multiple methods fluently', function () {
    $builder = createBuilder('POST');
    $builder->url('https://api.example.com/data')
        ->expectHeader('Content-Type', 'application/json')
        ->expectJson(['test' => 'data'])
        ->respondWithStatus(201)
        ->respondJson(['id' => 1, 'created' => true])
        ->respondWithHeader('Location', '/data/1')
        ->delay(0.5)
        ->persistent()
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('creates complex SSE scenario', function () {
    $builder = createBuilder('GET');
    $builder->url('https://api.example.com/stream')
        ->sseFailUntilAttempt(2, [
            ['event' => 'connected', 'data' => '{"status":"ok"}', 'id' => '1'],
        ])
        ->persistent()
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('creates retry scenario with custom responses', function () {
    $builder = createBuilder('GET');
    $builder->url('https://api.example.com/unstable')
        ->failWithSequence([
            ['error' => 'Network timeout', 'delay' => 0.1],
            ['error' => 'Connection refused', 'delay' => 0.2],
            ['status' => 503, 'error' => 'Service unavailable'],
        ], ['success' => true, 'message' => 'Finally worked'])
    ;

    expect($builder)->toBeInstanceOf(MockRequestBuilder::class);
});

test('registers mock with handler', function () {
    $handler = new TestingHttpHandler();
    $builder = new MockRequestBuilder($handler, 'GET');

    $builder->url('https://api.example.com/test')
        ->respondJson(['status' => 'ok'])
        ->register();

    expect($handler)->toBeInstanceOf(TestingHttpHandler::class);
});
