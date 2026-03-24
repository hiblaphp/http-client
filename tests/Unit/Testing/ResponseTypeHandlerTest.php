<?php

declare(strict_types=1);

use Hibla\HttpClient\Response;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\Testing\Utilities\Handlers\ResponseTypeHandler;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\Promise\Promise;

describe('ResponseTypeHandler', function () {

    describe('handleMockedResponse', function () {
        it('throws exception for download with empty destination', function () {
            $factory = Mockery::mock(ResponseFactory::class);
            $fileManager = Mockery::mock(FileManager::class);
            $mock = Mockery::mock(MockedRequest::class);

            $mock->shouldReceive('isPersistent')->andReturn(false);

            $handler = new ResponseTypeHandler($factory, $fileManager);
            $mockedRequests = [$mock];
            $match = ['mock' => $mock, 'index' => 0];

            expect(fn () => $handler->handleMockedResponse(
                $match,
                ['download' => ''],
                $mockedRequests,
                'https://example.com',
                'GET'
            ))->toThrow(InvalidArgumentException::class, 'Download destination must be a non-empty string');
        });

        it('removes non-persistent mocks after use', function () {
            $factory = Mockery::mock(ResponseFactory::class);
            $fileManager = Mockery::mock(FileManager::class);
            $mock = Mockery::mock(MockedRequest::class);
            $response = Mockery::mock(Response::class);

            $promise = new Promise(function ($resolve) use ($response) {
                $resolve($response);
            });

            $mock->shouldReceive('isPersistent')->andReturn(false);
            $factory->shouldReceive('createMockedResponse')->andReturn($promise);

            $handler = new ResponseTypeHandler($factory, $fileManager);
            $mockedRequests = [$mock];
            $match = ['mock' => $mock, 'index' => 0];

            $handler->handleMockedResponse(
                $match,
                [],
                $mockedRequests,
                'https://example.com',
                'GET'
            );

            expect($mockedRequests)->toBeEmpty();
        });

        it('keeps persistent mocks after use', function () {
            $factory = Mockery::mock(ResponseFactory::class);
            $fileManager = Mockery::mock(FileManager::class);
            $mock = Mockery::mock(MockedRequest::class);
            $response = Mockery::mock(Response::class);

            $promise = new Promise(function ($resolve) use ($response) {
                $resolve($response);
            });

            $mock->shouldReceive('isPersistent')->andReturn(true);
            $factory->shouldReceive('createMockedResponse')->andReturn($promise);

            $handler = new ResponseTypeHandler($factory, $fileManager);
            $mockedRequests = [$mock];
            $match = ['mock' => $mock, 'index' => 0];

            $handler->handleMockedResponse(
                $match,
                [],
                $mockedRequests,
                'https://example.com',
                'GET'
            );

            expect($mockedRequests)->toHaveCount(1);
        });
    });

    afterEach(function () {
        Mockery::close();
    });
});
