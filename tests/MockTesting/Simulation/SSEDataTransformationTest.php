<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\SSE\SSEDataFormat;
beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('SSE Mock Data Transformations', function () {
    test('it decodes JSON data from mock when format is DecodedJson', function () {
        Http::mock('GET')
            ->url('/sse-json')
            ->respondWithSSE([
                ['data' => json_encode(['id' => 1, 'status' => 'active'])]
            ])
            ->register();

        $receivedData = null;

        Http::sse('/sse-json')
            ->dataFormat(SSEDataFormat::DecodedJson)
            ->onEvent(function ($data) use (&$receivedData) {
                $receivedData = $data;
            })
            ->connect()
            ->wait();

        expect($receivedData)->toBeArray()
            ->and($receivedData['id'])->toBe(1)
            ->and($receivedData['status'])->toBe('active');
    });

    test('it converts mock event to array when format is Array', function () {
        Http::mock('GET')
            ->url('/sse-array')
            ->respondWithSSE([
                ['id' => 'evt-001', 'event' => 'update', 'data' => 'some-payload']
            ])
            ->register();

        $receivedArray = null;

        Http::sse('/sse-array')
            ->dataFormat(SSEDataFormat::Array)
            ->onEvent(function ($array) use (&$receivedArray) {
                $receivedArray = $array;
            })
            ->connect()
            ->wait();

        expect($receivedArray)->toBeArray()
            ->and($receivedArray['id'])->toBe('evt-001')
            ->and($receivedArray['event'])->toBe('update')
            ->and($receivedArray['data'])->toBe('some-payload');
    });

    test('it applies custom map() logic to mocked events', function () {
        Http::mock('GET')
            ->url('/sse-map')
            ->respondWithSSE([
                ['data' => '100'],
                ['data' => '200']
            ])
            ->register();

        $numbers = [];

        Http::sse('/sse-map')
            ->dataFormat(SSEDataFormat::Raw)
            ->map(fn($raw) => ((int)$raw) * 2)
            ->onEvent(function ($val) use (&$numbers) {
                $numbers[] = $val;
            })
            ->connect()
            ->wait();

        expect($numbers)->toBe([200, 400]);
    });
});