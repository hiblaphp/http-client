<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing;

use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\HttpClient\Interfaces\StreamingResponseInterface;
use Hibla\HttpClient\SSE\CancelableSSEPromise;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\Testing\Interfaces\AssertsCookiesInterface;
use Hibla\HttpClient\Testing\Interfaces\AssertsDownloadsInterface;
use Hibla\HttpClient\Testing\Interfaces\AssertsHeadersInterface;
use Hibla\HttpClient\Testing\Interfaces\AssertsRequestBodyInterface;
use Hibla\HttpClient\Testing\Interfaces\AssertsRequestsExtendedInterface;
use Hibla\HttpClient\Testing\Interfaces\AssertsRequestsInterface;
use Hibla\HttpClient\Testing\Interfaces\AssertsSSEInterface;
use Hibla\HttpClient\Testing\Interfaces\AssertsStreamsInterface;
use Hibla\HttpClient\Testing\Interfaces\AssertsUploadsInterface;
use Hibla\HttpClient\Testing\Traits\Assertions\AssertsCookies;
use Hibla\HttpClient\Testing\Traits\Assertions\AssertsDownloads;
use Hibla\HttpClient\Testing\Traits\Assertions\AssertsHeaders;
use Hibla\HttpClient\Testing\Traits\Assertions\AssertsRequestBody;
use Hibla\HttpClient\Testing\Traits\Assertions\AssertsRequests;
use Hibla\HttpClient\Testing\Traits\Assertions\AssertsRequestsExtended;
use Hibla\HttpClient\Testing\Traits\Assertions\AssertsSSE;
use Hibla\HttpClient\Testing\Traits\Assertions\AssertsStreams;
use Hibla\HttpClient\Testing\Traits\Assertions\AssertsUploads;
use Hibla\HttpClient\Testing\Utilities\CookieManager;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\Testing\Utilities\NetworkSimulator;
use Hibla\HttpClient\Testing\Utilities\RequestExecutor;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\HttpClient\Traits\StreamTrait;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Robust HTTP testing handler with comprehensive mocking capabilities.
 */
class TestingHttpHandler extends HttpHandler implements
    AssertsRequestsInterface,
    AssertsHeadersInterface,
    AssertsCookiesInterface,
    AssertsSSEInterface,
    AssertsDownloadsInterface,
    AssertsStreamsInterface,
    AssertsRequestBodyInterface,
    AssertsRequestsExtendedInterface,
    AssertsUploadsInterface
{
    use StreamTrait;
    use AssertsRequests;
    use AssertsHeaders;
    use AssertsCookies;
    use AssertsSSE;
    use AssertsDownloads;
    use AssertsStreams;
    use AssertsRequestBody;
    use AssertsRequestsExtended;
    use AssertsUploads;

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
        $this->responseFactory = new ResponseFactory($this->networkSimulator, $this);

        $this->requestExecutor = new RequestExecutor(
            $this->requestMatcher,
            $this->responseFactory,
            $this->fileManager,
            $this->cookieManager,
            $this->requestRecorder,
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
     * Throw exception when an unexpected request is made.
     */
    public function throwOnUnexpected(bool $throw = true): self
    {
        $this->globalSettings['throw_on_unexpected'] = $throw;

        return $this;
    }

    /**
     * Allow requests that don't match a mock to be sent to the real network.
     * Automatically disables throwing exceptions on unexpected requests.
     */
    public function enablePassthrough(): self
    {
        $this->globalSettings['allow_passthrough'] = true;
        $this->globalSettings['throw_on_unexpected'] = false;

        return $this;
    }

    /**
     * Prevent requests that don't match a mock from being sent.
     * Will throw an exception if a request is made without a matching mock.
     */
    public function disablePassthrough(): self
    {
        $this->globalSettings['allow_passthrough'] = false;
        $this->globalSettings['throw_on_unexpected'] = true;

        return $this;
    }

    /**
     * Send an HTTP request with mocking support.
     *
     * @return PromiseInterface<ResponseInterface>
     */
    public function sendRequest(string $url, array $curlOptions, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        $mockedRequests = array_values($this->mockedRequests);

        return $this->requestExecutor->executeSendRequest(
            $url,
            $curlOptions,
            $mockedRequests,
            $this->globalSettings,
            $retryConfig,
            fn (string $url, array $curlOptions, ?RetryConfig $retryConfig) => parent::sendRequest($url, $curlOptions, $retryConfig)
        );
    }

    /**
     * Stream data from a URL with chunk callbacks.
     *
     * @param array<int|string, mixed> $options
     * @return PromiseInterface<StreamingResponseInterface>
     */
    public function stream(string $url, array $options = [], ?callable $onChunk = null): PromiseInterface
    {
        $mockedRequests = array_values($this->mockedRequests);

        $options['stream'] = true;
        if ($onChunk !== null) {
            $options['on_chunk'] = $onChunk;
        }

        return $this->requestExecutor->executeSendRequest(
            $url,
            $options,
            $mockedRequests,
            $this->globalSettings,
            null,
            fn (string $u, array $o, ?RetryConfig $r) => parent::stream($u, $o, $onChunk)
        );
    }

    /**
     * Intercept and Mock file uploads.
     */
    public function upload(string $url, string $source, array $options = [], ?callable $onProgress = null): PromiseInterface
    {
        $mockedRequests = array_values($this->mockedRequests);

        $options['upload'] = $source;
        if ($onProgress !== null) {
            $options['on_progress'] = $onProgress;
        }

        return $this->requestExecutor->executeSendRequest(
            $url,
            $options,
            $mockedRequests,
            $this->globalSettings,
            null,
            fn (string $u, array $o, ?RetryConfig $r) => parent::upload($u, $source, $o, $onProgress)
        );
    }

    /**
     * Download a file to a destination path.
     *
     * @param string $url The URL to download from.
     * @param string|null $destination The destination path (auto-generated if null).
     * @param array<int|string, mixed> $options
     * @param (callable(\Hibla\HttpClient\ValueObjects\DownloadProgress): void)|null $onProgress
     * @return PromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}>
     */
    public function download(string $url, ?string $destination = null, array $options = [], ?callable $onProgress = null): PromiseInterface
    {
        if ($destination === null) {
            $destination = $this->fileManager->createTempFile('download_' . uniqid() . '.tmp');
        } else {
            $this->fileManager->trackFile($destination);
        }

        $mockedRequests = array_values($this->mockedRequests);

        $options['download'] = $destination;

        if ($onProgress !== null) {
            $options['on_progress'] = $onProgress;
        }

        return $this->requestExecutor->executeSendRequest(
            $url,
            $options,
            $mockedRequests,
            $this->globalSettings,
            null,
            fn (string $u, array $o, ?RetryConfig $r) => parent::download($u, $destination, $o, $onProgress)
        );
    }

    /**
     * Connect to a Server-Sent Events endpoint.
     *
     * @param array<int|string, mixed> $options
     * @return PromiseInterface<SSEResponseInterface>
     */
    public function sse(
        string $url,
        array $options = [],
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): PromiseInterface {
        $mockedRequests = array_values($this->mockedRequests);

        $innerPromise = $this->requestExecutor->executeSSE(
            $url,
            $options,
            $mockedRequests,
            $this->globalSettings,
            $onEvent,
            $onError,
            fn (string $u, array $o, ?callable $onEv, ?callable $onErr, ?SSEReconnectConfig $rec) => parent::sse($u, $o, $onEv, $onErr, $rec),
            $reconnectConfig
        );

        return new CancelableSSEPromise($innerPromise);
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
