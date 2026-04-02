<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\TestingHttpHandler;
use Hibla\HttpClient\Testing\Utilities\CookieManager;
use Hibla\HttpClient\Testing\Utilities\Executors\SSERequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Executors\StandardRequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Factories\SSE\ImmediateSSEEmitter;
use Hibla\HttpClient\Testing\Utilities\Factories\SSE\PeriodicSSEEmitter;
use Hibla\HttpClient\Testing\Utilities\Factories\SSE\RetryableSSEResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Factories\SSE\SSEResponseFactory;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\HttpClient\Testing\Utilities\Handlers\ResponseTypeHandler;
use Hibla\HttpClient\Testing\Utilities\NetworkSimulator;
use Hibla\HttpClient\Testing\Utilities\RequestExecutor;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Validators\RequestValidator;
use Hibla\Promise\Promise;

pest()->extend(Tests\TestCase::class)->in('Feature');
pest()->extend(Tests\TestCase::class)->in('Unit');
pest()->extend(Tests\TestCase::class)->in('Integration');

function testingHttpHandler(): TestingHttpHandler
{
    return new TestingHttpHandler();
}

function getPrivateProperty($object, string $property)
{
    $reflection = new ReflectionClass($object);
    $prop = $reflection->getProperty($property);

    return $prop->getValue($object);
}

function callPrivateMethod($object, string $method, array $args = [])
{
    $reflection = new ReflectionClass($object);
    $method = $reflection->getMethod($method);

    return $method->invoke($object, ...$args);
}

function createCookieManager(bool $autoManage = true): CookieManager
{
    return new CookieManager($autoManage);
}

function createFileManager(bool $autoManage = true): FileManager
{
    return new FileManager($autoManage);
}

function createNetworkSimulator(): NetworkSimulator
{
    return new NetworkSimulator();
}

function createNetworkSimulatorWithFailure(float $failureRate = 1.0, ?string $errorMessage = null): NetworkSimulator
{
    $simulator = new NetworkSimulator();
    $simulator->enable([
        'failure_rate' => $failureRate,
        'default_delay' => 0.0,
    ]);

    return $simulator;
}

function createNetworkSimulatorWithTimeout(float $timeoutRate = 1.0, float $timeoutDelay = 0.0): NetworkSimulator
{
    $simulator = new NetworkSimulator();
    $simulator->enable([
        'timeout_rate' => $timeoutRate,
        'timeout_delay' => $timeoutDelay,
        'default_delay' => 0.0,
    ]);

    return $simulator;
}

function createNetworkSimulatorWithRetryableFailure(float $retryableFailureRate = 1.0): NetworkSimulator
{
    $simulator = new NetworkSimulator();
    $simulator->enable([
        'retryable_failure_rate' => $retryableFailureRate,
        'default_delay' => 0.0,
    ]);

    return $simulator;
}

function createNetworkHandler(NetworkSimulator $simulator): NetworkSimulationHandler
{
    return new NetworkSimulationHandler($simulator);
}
function createImmediateSSEEmitter(): ImmediateSSEEmitter
{
    return new ImmediateSSEEmitter();
}

function createPeriodicEmitter(): PeriodicSSEEmitter
{
    return new PeriodicSSEEmitter();
}

function createPromise(): Promise
{
    return new Promise();
}

function createMockRequest(): MockedRequest
{
    return new MockedRequest();
}

function createMockedSSERequest(
    array $events = [],
    int $statusCode = 200,
    array $headers = [],
    ?array $sseStreamConfig = null
): MockedRequest {
    $mock = new MockedRequest();
    $mock->setStatusCode($statusCode);

    foreach ($headers as $name => $value) {
        if (is_array($value)) {
            foreach ($value as $val) {
                $mock->addResponseHeader($name, $val);
            }
        } else {
            $mock->addResponseHeader($name, $value);
        }
    }

    $mock->setSSEEvents($events);

    if ($sseStreamConfig !== null) {
        $mock->setSSEStreamConfig($sseStreamConfig);
    }

    return $mock;
}

function createTempDir(): string
{
    return sys_get_temp_dir() . '/test_downloads_' . uniqid();
}

function cleanupTempDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

function createRequestMatcher(): RequestMatcher
{
    return mock(RequestMatcher::class);
}

function createResponseFactory(): ResponseFactory
{
    return mock(ResponseFactory::class);
}

function createRequestRecorder(): RequestRecorder
{
    return mock(RequestRecorder::class);
}

function createRequestValidator(): RequestValidator
{
    return mock(RequestValidator::class);
}

function createSSERequestExecutor(
    ?RequestMatcher $requestMatcher = null,
    ?ResponseFactory $responseFactory = null,
    ?RequestRecorder $requestRecorder = null
): SSERequestExecutor {
    return new SSERequestExecutor(
        $requestMatcher ?? createRequestMatcher(),
        $responseFactory ?? createResponseFactory(),
        $requestRecorder ?? createRequestRecorder()
    );
}

function createRequestExecutor(): RequestExecutor
{
    return new RequestExecutor(
        new RequestMatcher(),
        new ResponseFactory(new NetworkSimulator()),
        new FileManager(),
        new CookieManager(),
        new RequestRecorder()
    );
}

function createStandardRequestExecutor(?FileManager $fileManager = null): StandardRequestExecutor
{
    $fileManager ??= new FileManager();
    $responseFactory = new ResponseFactory(new NetworkSimulator());

    return new StandardRequestExecutor(
        new RequestMatcher(),
        $responseFactory,
        new CookieManager(),
        new RequestRecorder(),
        new RequestValidator(),
        new ResponseTypeHandler($responseFactory, $fileManager)
    );
}

function createSSEResponseFactory(?NetworkSimulator $simulator = null): SSEResponseFactory
{
    $simulator ??= new NetworkSimulator();
    $handler = createNetworkHandler($simulator);

    return new SSEResponseFactory($handler);
}

function createSSEExecutor(): SSERequestExecutor
{
    return new SSERequestExecutor(
        new RequestMatcher(),
        new ResponseFactory(new NetworkSimulator()),
        new RequestRecorder()
    );
}

function createRetryableSSEResponseFactory(?NetworkSimulator $simulator = null): RetryableSSEResponseFactory
{
    $simulator ??= new NetworkSimulator();
    $handler = createNetworkHandler($simulator);

    return new RetryableSSEResponseFactory($handler);
}

function createReconnectConfig(int $maxAttempts = 3, float $initialDelay = 0.05): SSEReconnectConfig
{
    return new SSEReconnectConfig(
        maxAttempts: $maxAttempts,
        initialDelay: $initialDelay,
        maxDelay: 1.0,
        backoffMultiplier: 2.0
    );
}
