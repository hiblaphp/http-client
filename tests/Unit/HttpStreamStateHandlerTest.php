<?php

declare(strict_types=1);

namespace Tests\Unit;

use Hibla\HttpClient\Handlers\HttpStreamStateHandler;
use Hibla\Promise\Promise;

use function Hibla\await;

describe('HttpStreamStateHandler', function () {
    $handler = null;
    $resource = null;

    beforeEach(function () use (&$handler, &$resource) {
        $resource = fopen('php://temp', 'w+b');
        $handler = new HttpStreamStateHandler($resource);
    });

    afterEach(function () use (&$handler) {
        $handler->close();
    });

    it('fulfills a queued read when data is pushed to buffer', function () use (&$handler) {
        $promise = new Promise();
        $handler->enqueueRead(5, $promise);
        $handler->writeToBuffer('hello world');
        expect(await($promise))->toBe('hello');
    });

    it('resolves immediately if data is already in the resource', function () use (&$handler) {
        $handler->writeToBuffer('pre-existing');
        $promise = new Promise();
        $handler->enqueueRead(3, $promise);
        expect(await($promise))->toBe('pre');
    });

    it('handles multiple queued reads sequentially', function () use (&$handler) {
        $p1 = new Promise();
        $p2 = new Promise();
        $handler->enqueueRead(5, $p1);
        $handler->enqueueRead(5, $p2);
        $handler->writeToBuffer('hello');
        expect(await($p1))->toBe('hello');
        $handler->writeToBuffer('world');
        expect(await($p2))->toBe('world');
    });

    it('resolves pending reads with null when markEof is called', function () use (&$handler) {
        $promise = new Promise();
        $handler->enqueueRead(10, $promise);
        $handler->markEof();
        expect(await($promise))->toBeNull();
        expect($handler->isEof())->toBeTrue();
    });

    it('removes promise from queue when cancelled', function () use (&$handler) {
        $p1 = new Promise();
        $p2 = new Promise();
        $handler->enqueueRead(10, $p1);
        $handler->enqueueRead(11, $p2);
        $p1->cancel();
        $handler->writeToBuffer('target_data');
        expect(await($p2))->toBe('target_data');
    });

    it('rejects all pending reads when closed', function () use (&$handler) {
        $promise = new Promise();
        $handler->enqueueRead(10, $promise);
        $handler->close();
        expect(fn () => await($promise))->toThrow(\RuntimeException::class, 'Stream closed');
    });

    it('respects the prepend buffer (overflow logic)', function () use (&$handler) {
        $handler->setPrependBuffer('cached_');
        $promise = new Promise();
        $handler->enqueueRead(11, $promise);
        $handler->writeToBuffer('data');
        expect(await($promise))->toBe('cached_data');
    });

    it('pumps large data across multiple promises', function () use (&$handler) {
        $handler->writeToBuffer(str_repeat('a', 100));
        $p1 = new Promise();
        $p2 = new Promise();
        $handler->enqueueRead(60, $p1);
        $handler->enqueueRead(60, $p2);
        expect(strlen(await($p1)))->toBe(60);
        expect(strlen(await($p2)))->toBe(40);
    });

    it('maintains correct read position after seeking', function () use (&$handler) {
        $handler->writeToBuffer('0123456789');
        $handler->clearBuffers();
        $promise = new Promise();
        $handler->enqueueRead(2, $promise);
        expect(await($promise))->toBe('01');
    });
});
