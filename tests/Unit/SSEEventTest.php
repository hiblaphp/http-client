<?php

use Hibla\HttpClient\SSE\SSEEvent;

describe('SSEEvent', function () {
    it('creates an SSE event with all fields', function () {
        $rawFields = ['custom' => ['value1', 'value2']];
        $event = new SSEEvent(
            id: '123',
            event: 'message',
            data: 'Hello World',
            retry: 5000,
            rawFields: $rawFields
        );

        expect($event->id)->toBe('123')
            ->and($event->event)->toBe('message')
            ->and($event->data)->toBe('Hello World')
            ->and($event->retry)->toBe(5000)
            ->and($event->rawFields)->toBe($rawFields)
        ;
    });

    it('creates event with minimal fields', function () {
        $event = new SSEEvent(data: 'Test');

        expect($event->id)->toBeNull()
            ->and($event->event)->toBeNull()
            ->and($event->data)->toBe('Test')
            ->and($event->retry)->toBeNull()
            ->and($event->rawFields)->toBe([])
        ;
    });

    it('creates completely empty event', function () {
        $event = new SSEEvent();

        expect($event->id)->toBeNull()
            ->and($event->event)->toBeNull()
            ->and($event->data)->toBeNull()
            ->and($event->retry)->toBeNull()
        ;
    });

    it('identifies null data as keep-alive', function () {
        $event = new SSEEvent(data: null);

        expect($event->isKeepAlive())->toBeTrue();
    });

    it('identifies empty string as keep-alive', function () {
        $event = new SSEEvent(data: '');

        expect($event->isKeepAlive())->toBeTrue();
    });

    it('identifies whitespace-only data as keep-alive', function () {
        expect((new SSEEvent(data: '   '))->isKeepAlive())->toBeTrue()
            ->and((new SSEEvent(data: "\n\t  "))->isKeepAlive())->toBeTrue()
        ;
    });

    it('identifies event with content as not keep-alive', function () {
        $event = new SSEEvent(data: 'actual content');

        expect($event->isKeepAlive())->toBeFalse();
    });

    it('identifies event with whitespace and content as not keep-alive', function () {
        $event = new SSEEvent(data: '  content  ');

        expect($event->isKeepAlive())->toBeFalse();
    });

    it('gets custom event type', function () {
        $event = new SSEEvent(event: 'custom-event');

        expect($event->getType())->toBe('custom-event');
    });

    it('defaults to message type when not specified', function () {
        $event = new SSEEvent(data: 'test');

        expect($event->getType())->toBe('message');
    });

    it('converts event to array with all fields', function () {
        $rawFields = ['custom' => ['val']];
        $event = new SSEEvent(
            id: '456',
            event: 'update',
            data: 'Data content',
            retry: 3000,
            rawFields: $rawFields
        );

        $array = $event->toArray();

        expect($array)->toBe([
            'id' => '456',
            'event' => 'update',
            'data' => 'Data content',
            'retry' => 3000,
            'raw_fields' => $rawFields,
        ]);
    });

    it('converts minimal event to array', function () {
        $event = new SSEEvent(data: 'Only data');

        $array = $event->toArray();

        expect($array)->toBe([
            'id' => null,
            'event' => null,
            'data' => 'Only data',
            'retry' => null,
            'raw_fields' => [],
        ]);
    });

    it('converts completely empty event to array', function () {
        $event = new SSEEvent();

        $array = $event->toArray();

        expect($array)->toBe([
            'id' => null,
            'event' => null,
            'data' => null,
            'retry' => null,
            'raw_fields' => [],
        ]);
    });

    it('stores raw fields with multiple values', function () {
        $rawFields = [
            'data' => ['line1', 'line2', 'line3'],
            'custom' => ['value1', 'value2'],
        ];
        $event = new SSEEvent(rawFields: $rawFields);

        expect($event->rawFields)->toBe($rawFields)
            ->and($event->rawFields['data'])->toHaveCount(3)
            ->and($event->rawFields['custom'])->toHaveCount(2)
        ;
    });

    it('handles numeric retry values', function () {
        expect((new SSEEvent(retry: 0))->retry)->toBe(0)
            ->and((new SSEEvent(retry: 1000))->retry)->toBe(1000)
            ->and((new SSEEvent(retry: 999999))->retry)->toBe(999999);
    });
});
