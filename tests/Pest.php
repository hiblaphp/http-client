<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

use Hibla\HttpClient\Handlers\CacheHandler;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\CacheManager;
use Hibla\HttpClient\Testing\Utilities\CookieManager;
use Hibla\HttpClient\Testing\Utilities\Executors\FetchRequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Executors\RetryableRequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Executors\SSERequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Executors\StandardRequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Factories\SSE\ImmediateSSEEmitter;
use Hibla\HttpClient\Testing\Utilities\Factories\SSE\PeriodicSSEEmitter;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\HttpClient\Testing\Utilities\NetworkSimulator;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Validators\RequestValidator;
use Hibla\Promise\CancellablePromise;

pest()->extend(Tests\TestCase::class)->in('Feature');
pest()->extend(Tests\TestCase::class)->in('Unit');
pest()->extend(Tests\TestCase::class)->in('Integration');

function getPrivateProperty($object, string $property)
{
    $reflection = new ReflectionClass($object);
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(true);

    return $prop->getValue($object);
}

function callPrivateMethod($object, string $method, array $args = [])
{
    $reflection = new ReflectionClass($object);
    $method = $reflection->getMethod($method);
    $method->setAccessible(true);

    return $method->invoke($object, ...$args);
}

function createCacheManager(): CacheManager
{
    return new CacheManager();
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

function createCacheHandler(CacheManager $manager): CacheHandler
{
    return new CacheHandler($manager);
}

function createImmediateSSEEmitter(): ImmediateSSEEmitter
{
    return new ImmediateSSEEmitter();
}

function createPeriodicEmitter(): PeriodicSSEEmitter
{
    return new PeriodicSSEEmitter();
}

function createCancellablePromise(): CancellablePromise
{
    return new CancellablePromise();
}

function createMockRequest(): MockedRequest
{
    return new MockedRequest(); // Use the real class
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

function createMockCacheHandler(): \Hibla\HttpClient\Testing\Utilities\Handlers\CacheHandler
{
    return mock(\Hibla\HttpClient\Testing\Utilities\Handlers\CacheHandler::class);
}

function createFetchRequestExecutor(
    ?RequestMatcher $requestMatcher = null,
    ?ResponseFactory $responseFactory = null,
    ?FileManager $fileManager = null,
    ?RequestRecorder $requestRecorder = null,
    ?\Hibla\HttpClient\Testing\Utilities\Handlers\CacheHandler $cacheHandler = null,
    ?RequestValidator $validator = null
): FetchRequestExecutor {
    return new FetchRequestExecutor(
        $requestMatcher ?? createRequestMatcher(),
        $responseFactory ?? createResponseFactory(),
        $fileManager ?? createFileManager(),
        $requestRecorder ?? createRequestRecorder(),
        $cacheHandler ?? createMockCacheHandler(),
        $validator ?? createRequestValidator()
    );
}

function createRetryableRequestExecutor(
    ?RequestMatcher $requestMatcher = null,
    ?ResponseFactory $responseFactory = null,
    ?RequestRecorder $requestRecorder = null
): RetryableRequestExecutor {
    return new RetryableRequestExecutor(
        $requestMatcher ?? createRequestMatcher(),
        $responseFactory ?? createResponseFactory(),
        $requestRecorder ?? createRequestRecorder()
    );
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

function createStandardRequestExecutor(
    ?RequestMatcher $requestMatcher = null,
    ?ResponseFactory $responseFactory = null,
    ?CookieManager $cookieManager = null,
    ?RequestRecorder $requestRecorder = null,
    ?\Hibla\HttpClient\Testing\Utilities\Handlers\CacheHandler $cacheHandler = null,
    ?RequestValidator $validator = null
): StandardRequestExecutor {
    return new StandardRequestExecutor(
        $requestMatcher ?? createRequestMatcher(),
        $responseFactory ?? createResponseFactory(),
        $cookieManager ?? createCookieManager(),
        $requestRecorder ?? createRequestRecorder(),
        $cacheHandler ?? createMockCacheHandler(),
        $validator ?? createRequestValidator()
    );
}