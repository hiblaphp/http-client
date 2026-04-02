<?php

declare(strict_types=1);

use Hibla\HttpClient\HttpClient;
use PHPUnit\Framework\AssertionFailedError;

describe('Edge Cases', function () {
    test('assertions work with wildcards in URLs', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/api/users/123')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com/api/users/123')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestMatchingUrl('GET', 'https://example.com/api/users/*'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertions handle case-insensitive HTTP methods', function () {
        $handler = testingHttpHandler();
        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->send('post', 'https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestMade('POST', 'https://example.com'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertions handle empty request history', function () {
        $handler = testingHttpHandler();

        expect($handler->getLastRequest())->toBeNull()
            ->and($handler->getRequest(0))->toBeNull()
            ->and($handler->getRequestHistory())->toBeEmpty()
        ;
    });

    test('assertions handle multiple requests to same URL', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);
        $client->get('https://example.com')->wait();
        $client->get('https://example.com')->wait();
        $client->get('https://example.com')->wait();

        expect(fn () => $handler->assertRequestCount(3))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertions handle nested JSON structures', function () {
        $handler = testingHttpHandler();
        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->withJson([
                'user' => [
                    'profile' => [
                        'name' => 'John',
                        'age' => 30,
                    ],
                ],
            ])
            ->post('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestJsonPath('POST', 'https://example.com', 'user.profile.name', 'John'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertions handle array header values', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->withHeader('Accept', ['application/json', 'text/html'])
            ->get('https://example.com')
            ->wait()
        ;

        $request = $handler->getLastRequest();
        expect($request)->not->toBeNull();
    });

    test('download assertions handle temp files', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/file.txt')
            ->respondWithStatus(200)
            ->respondWith('test body content')
            ->register()
        ;

        // TestingHttpHandler provides a file manager to easily get auto-cleaned temp files
        $tempFile = $handler->createTempFile('test_download.txt');

        $result = (new HttpClient())
            ->setHandler($handler)
            ->download('https://example.com/file.txt', $tempFile)
            ->wait()
        ;

        expect($result['file'])->toBeString()
            ->and(file_exists($result['file']))->toBeTrue()
            ->and(file_get_contents($result['file']))->toBe('test body content')
        ;
    });

    test('stream assertions handle callback presence', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/stream')->respondWithStatus(200)->register();

        $chunks = [];
        (new HttpClient())
            ->setHandler($handler)
            ->stream('https://example.com/stream', function ($chunk) use (&$chunks) {
                $chunks[] = $chunk;
            })
            ->wait()
        ;

        expect(fn () => $handler->assertStreamWithCallback('https://example.com/stream'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('SSE assertions handle URL patterns with wildcards', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events/stream-123')
            ->respondWithHeader('Accept', 'text/event-stream') // SSE mocks typically expect this
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register()
        ;

        // HttpClient::sse() returns an SSEBuilderInterface which requires calling connect()
        (new HttpClient())
            ->setHandler($handler)
            ->sse('https://example.com/events/stream-123')
            ->connect()
            ->wait()
        ;

        expect(fn () => $handler->assertSSEConnectionMade('https://example.com/events/*'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });
});

describe('Debugging Helpers', function () {
    test('dumpLastRequest outputs request information', function () {
        $handler = testingHttpHandler();
        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->withHeader('X-Custom', 'header-value')
            ->withJson(['key' => 'value'])
            ->post('https://example.com')
            ->wait()
        ;

        ob_start();
        $handler->dumpLastRequest();
        $output = ob_get_clean();

        expect($output)->toContain('Last Request')
            ->and($output)->toContain('Method: POST')
            ->and($output)->toContain('URL: https://example.com')
            ->and($output)->toContain('x-custom')
            ->and($output)->toContain('Parsed JSON')
        ;
    });

    test('dumpDownloads outputs download information', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/file.txt')->respondWithStatus(200)->register();

        $tempFile = $handler->createTempFile('dump_test.txt');

        (new HttpClient())
            ->setHandler($handler)
            ->download('https://example.com/file.txt', $tempFile)
            ->wait()
        ;

        ob_start();
        $handler->dumpDownloads();
        $output = ob_get_clean();

        expect($output)->toContain('Downloads')
            ->and($output)->toContain('https://example.com/file.txt')
            ->and($output)->toContain('Destination:')
        ;
    });

    test('dumpStreams outputs stream information', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/stream')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->stream('https://example.com/stream')
            ->wait()
        ;

        ob_start();
        $handler->dumpStreams();
        $output = ob_get_clean();

        expect($output)->toContain('Streams')
            ->and($output)->toContain('https://example.com/stream')
            ->and($output)->toContain('Has callback:')
        ;
    });

    test('dumpRequestsByMethod filters by HTTP method', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/1')->respondWithStatus(200)->register();
        $handler->mock('POST')->url('https://example.com/2')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);
        $client->get('https://example.com/1')->wait();
        $client->post('https://example.com/2')->wait();

        ob_start();
        $handler->dumpRequestsByMethod('GET');
        $output = ob_get_clean();

        expect($output)->toContain('GET Requests')
            ->and($output)->toContain('https://example.com/1')
            ->and($output)->not->toContain('https://example.com/2')
        ;
    });
});
