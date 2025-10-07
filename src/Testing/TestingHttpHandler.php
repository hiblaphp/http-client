<?php

namespace Hibla\Http\Testing;

use Hibla\Http\CacheConfig;
use Hibla\Http\Handlers\HttpHandler;
use Hibla\Http\Interfaces\CookieJarInterface;
use Hibla\Http\RetryConfig;
use Hibla\Http\SSE\SSEReconnectConfig;
use Hibla\Http\Testing\Interfaces\AssertsCookiesInterface;
use Hibla\Http\Testing\Interfaces\AssertsHeadersInterface;
use Hibla\Http\Testing\Interfaces\AssertsRequestsInterface;
use Hibla\Http\Testing\Interfaces\AssertsSSEInterface;
use Hibla\Http\Testing\Traits\Assertions\AssertsCookies;
use Hibla\Http\Testing\Traits\Assertions\AssertsDownloads;
use Hibla\Http\Testing\Traits\Assertions\AssertsHeaders;
use Hibla\Http\Testing\Traits\Assertions\AssertsRequestBody;
use Hibla\Http\Testing\Traits\Assertions\AssertsRequests;
use Hibla\Http\Testing\Traits\Assertions\AssertsRequestsExtended;
use Hibla\Http\Testing\Traits\Assertions\AssertsSSE;
use Hibla\Http\Testing\Traits\Assertions\AssertsStreams;
use Hibla\Http\Testing\Utilities\CacheManager;
use Hibla\Http\Testing\Utilities\CookieManager;
use Hibla\Http\Testing\Utilities\FileManager;
use Hibla\Http\Testing\Utilities\NetworkSimulator;
use Hibla\Http\Testing\Utilities\RequestExecutor;
use Hibla\Http\Testing\Utilities\RequestMatcher;
use Hibla\Http\Testing\Utilities\RequestRecorder;
use Hibla\Http\Testing\Utilities\ResponseFactory;
use Hibla\Http\Traits\FetchOptionTrait;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Robust HTTP testing handler with comprehensive mocking capabilities.
 */
class TestingHttpHandler extends HttpHandler implements
    AssertsRequestsInterface,
    AssertsHeadersInterface,
    AssertsCookiesInterface,
    AssertsSSEInterface
{
    use FetchOptionTrait;
    use AssertsRequests;
    use AssertsHeaders;
    use AssertsCookies;
    use AssertsSSE;
    use AssertsDownloads;
    use AssertsStreams;
    use AssertsRequestBody;
    use AssertsRequestsExtended;


    /**
     * List of mocked HTTP requests.
     *
     * @var list<MockedRequest>
     */
    private array $mockedRequests = [];

    /**
     * Minimum seconds for global random delay.
     */
    private ?float $globalRandomDelayMin = null;

    /**
     * Maximum seconds for global random delay.
     */
    private ?float $globalRandomDelayMax = null;

    /**
     * Global testing configuration settings.
     *
     * @var array<string, mixed>
     */
    private array $globalSettings = [
        'record_requests' => true,
        'strict_matching' => false,
        'allow_passthrough' => false,
        'throw_on_unexpected' => true,
    ];

    /**
     * Manages temporary file creation and cleanup.
     */
    private FileManager $fileManager;

    /**
     * Simulates network conditions like delays and failures.
     */
    private NetworkSimulator $networkSimulator;

    /**
     * Matches incoming requests against mocked requests.
     */
    private RequestMatcher $requestMatcher;

    /**
     * Creates mock HTTP responses.
     */
    private ResponseFactory $responseFactory;

    /**
     * Manages HTTP cookies for testing.
     */
    private CookieManager $cookieManager;

    /**
     * Executes HTTP requests with mocking support.
     */
    private RequestExecutor $requestExecutor;

    /**
     * Records all HTTP requests made during testing.
     */
    private RequestRecorder $requestRecorder;

    /**
     * Manages HTTP cache for testing.
     */
    private CacheManager $cacheManager;

    /**
     * Initialize the testing HTTP handler with all utilities.
     */
    public function __construct()
    {
        parent::__construct();
        $this->fileManager = new FileManager();
        $this->networkSimulator = new NetworkSimulator();
        $this->requestMatcher = new RequestMatcher();
        $this->cookieManager = new CookieManager();
        $this->requestRecorder = new RequestRecorder();
        $this->cacheManager = new CacheManager();
        $this->responseFactory = new ResponseFactory($this->networkSimulator, $this);

        $this->requestExecutor = new RequestExecutor(
            $this->requestMatcher,
            $this->responseFactory,
            $this->fileManager,
            $this->cookieManager,
            $this->requestRecorder,
            $this->cacheManager
        );
    }

    /**
     * Get the request recorder instance.
     */
    protected function getRequestRecorder(): RequestRecorder
    {
        return $this->requestRecorder;
    }

    /**
     * Get the request matcher instance.
     */
    protected function getRequestMatcher(): RequestMatcher
    {
        return $this->requestMatcher;
    }

    /**
     * Get the cookie manager instance.
     */
    protected function getCookieManager(): CookieManager
    {
        return $this->cookieManager;
    }

    /**
     * Create a new mock request builder.
     */
    public function mock(string $method = '*'): MockRequestBuilder
    {
        return new MockRequestBuilder($this, $method);
    }

    /**
     * Add a mocked request to the handler.
     */
    public function addMockedRequest(MockedRequest $request): void
    {
        $this->mockedRequests[] = $request;
    }

    /**
     * Get the cookie manager for manual cookie operations.
     */
    public function cookies(): CookieManager
    {
        return $this->cookieManager;
    }

    /**
     * Set a global cookie jar for all requests.
     */
    public function withGlobalCookieJar(?CookieJarInterface $jar = null): self
    {
        if ($jar === null) {
            $jar = $this->cookieManager->createCookieJar();
        }

        $this->cookieManager->setDefaultCookieJar($jar);

        return $this;
    }

    /**
     * Set a file-based cookie jar for all requests.
     */
    public function withGlobalFileCookieJar(?string $filename = null, bool $includeSessionCookies = true): self
    {
        if ($filename === null) {
            $filename = $this->cookieManager->createTempCookieFile();
        }

        $jar = $this->cookieManager->createFileCookieJar($filename, $includeSessionCookies);

        return $this;
    }

    /**
     * Add a random delay to all requests.
     */
    public function withGlobalRandomDelay(float $minSeconds, float $maxSeconds): self
    {
        if ($minSeconds > $maxSeconds) {
            throw new \InvalidArgumentException('Minimum delay cannot be greater than maximum delay');
        }

        $this->globalRandomDelayMin = $minSeconds;
        $this->globalRandomDelayMax = $maxSeconds;

        return $this;
    }

    /**
     * Remove global random delay from requests.
     */
    public function withoutGlobalRandomDelay(): self
    {
        $this->globalRandomDelayMin = null;
        $this->globalRandomDelayMax = null;

        return $this;
    }

    /**
     * Add network random delay with additional settings.
     *
     * @param array<float> $delayRange
     * @param array<string, mixed> $additionalSettings
     */
    public function withNetworkRandomDelay(array $delayRange, array $additionalSettings = []): self
    {
        /** @var array{failure_rate?: float, timeout_rate?: float, connection_failure_rate?: float, default_delay?: array<float>|float, timeout_delay?: array<float>|float, retryable_failure_rate?: float, random_delay?: array<float>|null} */
        $settings = array_merge($additionalSettings, ['random_delay' => $delayRange]);
        $this->networkSimulator->enable($settings);

        return $this;
    }

    /**
     * Enable network simulation with custom settings.
     *
     * @param array{failure_rate?: float, timeout_rate?: float, connection_failure_rate?: float, default_delay?: array<float>|float, timeout_delay?: array<float>|float, retryable_failure_rate?: float, random_delay?: array<float>|null} $settings
     */
    public function enableNetworkSimulation(array $settings = []): self
    {
        $this->networkSimulator->enable($settings);

        return $this;
    }

    /**
     * Disable network simulation.
     */
    public function disableNetworkSimulation(): self
    {
        $this->networkSimulator->disable();

        return $this;
    }

    /**
     * Simulate a poor network connection with high delays and failures.
     */
    public function withPoorNetwork(): self
    {
        return $this->enableNetworkSimulation([
            'random_delay' => [1.0, 5.0],
            'failure_rate' => 0.15,
            'timeout_rate' => 0.1,
            'connection_failure_rate' => 0.08,
            'retryable_failure_rate' => 0.12,
        ]);
    }

    /**
     * Simulate a fast network connection with minimal delays.
     */
    public function withFastNetwork(): self
    {
        return $this->enableNetworkSimulation([
            'random_delay' => [0.01, 0.1],
            'failure_rate' => 0.001,
            'timeout_rate' => 0.0,
            'connection_failure_rate' => 0.0,
            'retryable_failure_rate' => 0.001,
        ]);
    }

    /**
     * Simulate a mobile network connection with moderate delays.
     */
    public function withMobileNetwork(): self
    {
        return $this->enableNetworkSimulation([
            'random_delay' => [0.5, 3.0],
            'failure_rate' => 0.08,
            'timeout_rate' => 0.05,
            'connection_failure_rate' => 0.03,
            'retryable_failure_rate' => 0.1,
        ]);
    }

    /**
     * Simulate an unstable network with high variability.
     */
    public function withUnstableNetwork(): self
    {
        return $this->enableNetworkSimulation([
            'random_delay' => [0.2, 4.0],
            'failure_rate' => 0.2,
            'timeout_rate' => 0.15,
            'connection_failure_rate' => 0.1,
            'retryable_failure_rate' => 0.25,
        ]);
    }

    /**
     * Enable or disable automatic temporary file cleanup.
     */
    public function setAutoTempFileManagement(bool $enabled): self
    {
        $this->fileManager->setAutoManagement($enabled);

        return $this;
    }

    /**
     * Enable strict matching for mocked requests.
     */
    public function setStrictMatching(bool $strict): self
    {
        $this->globalSettings['strict_matching'] = $strict;

        return $this;
    }

    /**
     * Enable or disable request recording.
     */
    public function setRecordRequests(bool $enabled): self
    {
        $this->globalSettings['record_requests'] = $enabled;
        $this->requestRecorder->setRecordRequests($enabled);

        return $this;
    }

    /**
     * Allow unmocked requests to pass through to real HTTP.
     */
    public function setAllowPassthrough(bool $allow): self
    {
        $this->globalSettings['allow_passthrough'] = $allow;

        return $this;
    }

    /**
     * Throw exception when an unexpected request is made.
     */
    public function throwOnUnexpected(bool $throw = true): self
    {
        $this->globalSettings['throw_on_unexpected'] = $throw;

        return $this;
    }

    /**
     * Allow passthrough and disable throwing on unexpected requests.
     */
    public function allowPassthrough(bool $allow = true): self
    {
        $this->globalSettings['allow_passthrough'] = $allow;
        $this->globalSettings['throw_on_unexpected'] = ! $allow;

        return $this;
    }

    /**
     * Send an HTTP request with mocking support.
     */
    public function sendRequest(string $url, array $curlOptions, ?CacheConfig $cacheConfig = null, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        $mockedRequests = array_values($this->mockedRequests);

        return $this->requestExecutor->executeSendRequest(
            $url,
            $curlOptions,
            $mockedRequests,
            $this->globalSettings,
            $cacheConfig,
            $retryConfig,
            fn(string $url, array $curlOptions, ?CacheConfig $cacheConfig, ?RetryConfig $retryConfig) => parent::sendRequest($url, $curlOptions, $cacheConfig, $retryConfig)
        );
    }

    /**
     * Fetch a URL with mocking support.
     *
     * @param array<int|string, mixed> $options
     * @return PromiseInterface<\Hibla\Http\Response>|CancellablePromiseInterface<\Hibla\Http\StreamingResponse>|CancellablePromiseInterface<\Hibla\Http\SSE\SSEResponse>|CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}>
     */
    public function fetch(string $url, array $options = []): PromiseInterface|CancellablePromiseInterface
    {
        $mockedRequests = array_values($this->mockedRequests);
        /** @var array<string, mixed> $normalizedOptions */
        $normalizedOptions = $options;

        // @phpstan-ignore-next-line return.type
        return $this->requestExecutor->executeFetch(
            $url,
            $normalizedOptions,
            $mockedRequests,
            $this->globalSettings,
            fn(string $url, array $options) => parent::fetch($url, $options),
            [$this, 'createStream']
        );
    }

    /**
     * Stream data from a URL with chunk callbacks.
     *
     * @param array<int|string, mixed> $options
     * @return CancellablePromiseInterface<\Hibla\Http\StreamingResponse>
     */
    public function stream(string $url, array $options = [], ?callable $onChunk = null): CancellablePromiseInterface
    {
        $options['stream'] = true;
        if ($onChunk !== null) {
            $options['on_chunk'] = $onChunk;
        }

        /** @var CancellablePromiseInterface<\Hibla\Http\StreamingResponse> */
        return $this->fetch($url, $options);
    }

    /**
     * Download a file to a destination path.
     *
     * @param array<int|string, mixed> $options
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}>
     */
    public function download(string $url, ?string $destination = null, array $options = []): CancellablePromiseInterface
    {
        if ($destination === null) {
            $destination = $this->fileManager->createTempFile(
                'download_' . uniqid() . '.tmp'
            );
        } else {
            $this->fileManager->trackFile($destination);
        }

        $options['download'] = $destination;

        /** @var CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}> */
        return $this->fetch($url, $options);
    }

    /**
     * Connect to a Server-Sent Events endpoint.
     *
     * @param array<int|string, mixed> $options
     */
    public function sse(
        string $url,
        array $options = [],
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        $curlOptions = $this->normalizeFetchOptions($url, $options, true);
        $mockedRequests = array_values($this->mockedRequests);

        /** @var array<int, mixed> $normalizedCurlOptions */
        $normalizedCurlOptions = $curlOptions;

        return $this->requestExecutor->executeSSE(
            $url,
            $normalizedCurlOptions,
            $mockedRequests,
            $this->globalSettings,
            $onEvent,
            $onError,
            fn(string $url, array $options, ?callable $onEvent, ?callable $onError, ?SSEReconnectConfig $reconnectConfig) => parent::sse($url, $options, $onEvent, $onError, $reconnectConfig),
            $reconnectConfig
        );
    }

    /**
     * Get the temporary directory path.
     */
    public static function getTempPath(?string $filename = null): string
    {
        return FileManager::getTempPath($filename);
    }

    /**
     * Create a temporary directory.
     */
    public function createTempDirectory(string $prefix = 'http_test_'): string
    {
        return $this->fileManager->createTempDirectory($prefix);
    }

    /**
     * Create a temporary file with optional content.
     */
    public function createTempFile(?string $filename = null, string $content = ''): string
    {
        return $this->fileManager->createTempFile($filename, $content);
    }

    /**
     * Get the history of all recorded requests.
     *
     * @return array<int, Utilities\RecordedRequest>
     */
    public function getRequestHistory(): array
    {
        return $this->requestRecorder->getRequestHistory();
    }

    /**
     * Generate a random delay value within the configured range.
     */
    public function generateGlobalRandomDelay(): float
    {
        if ($this->globalRandomDelayMin === null || $this->globalRandomDelayMax === null) {
            return 0.0;
        }

        $precision = 1000000;
        $randomInt = random_int(
            (int) ($this->globalRandomDelayMin * $precision),
            (int) ($this->globalRandomDelayMax * $precision)
        );

        return $randomInt / $precision;
    }

    /**
     * Reset the handler state, clearing mocks and history.
     */
    public function reset(): void
    {
        $this->mockedRequests = [];
        $this->globalRandomDelayMin = null;
        $this->globalRandomDelayMax = null;
        $this->fileManager->cleanup();
        $this->cookieManager->cleanup();
        $this->requestRecorder->reset();
    }
}
