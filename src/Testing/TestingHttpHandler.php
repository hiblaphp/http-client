<?php

namespace Hibla\Http\Testing;

use Hibla\Http\CacheConfig;
use Hibla\Http\Handlers\HttpHandler;
use Hibla\Http\Interfaces\CookieJarInterface;
use Hibla\Http\RetryConfig;
use Hibla\Http\Testing\Exceptions\MockAssertionException;
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
class TestingHttpHandler extends HttpHandler
{
    use FetchOptionTrait;

    /** @var array<MockedRequest> */
    private array $mockedRequests = [];

    private ?float $globalRandomDelayMin = null;
    private ?float $globalRandomDelayMax = null;

    private array $globalSettings = [
        'record_requests' => true,
        'strict_matching' => false,
        'allow_passthrough' => true,
    ];

    private FileManager $fileManager;
    private NetworkSimulator $networkSimulator;
    private RequestMatcher $requestMatcher;
    private ResponseFactory $responseFactory;
    private CookieManager $cookieManager;
    private RequestExecutor $requestExecutor;
    private RequestRecorder $requestRecorder;
    private CacheManager $cacheManager;

    public function __construct()
    {
        parent::__construct();
        $this->fileManager = new FileManager;
        $this->networkSimulator = new NetworkSimulator;
        $this->requestMatcher = new RequestMatcher;
        $this->cookieManager = new CookieManager;
        $this->requestRecorder = new RequestRecorder;
        $this->cacheManager = new CacheManager;
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

    public function mock(string $method = '*'): MockRequestBuilder
    {
        return new MockRequestBuilder($this, $method);
    }

    public function addMockedRequest(MockedRequest $request): void
    {
        $this->mockedRequests[] = $request;
    }

    /**
     * Get the cookie testing service.
     */
    public function cookies(): CookieManager
    {
        return $this->cookieManager;
    }

    /**
     * Enable automatic cookie management for all requests.
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
     * Create and use a file-based cookie jar for all requests.
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
     * Enable global random delay for all requests.
     * This adds realistic network variance to all mocked requests.
     *
     * @param  float  $minSeconds  Minimum delay in seconds
     * @param  float  $maxSeconds  Maximum delay in seconds
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
     * Disable global random delay.
     */
    public function withoutGlobalRandomDelay(): self
    {
        $this->globalRandomDelayMin = null;
        $this->globalRandomDelayMax = null;

        return $this;
    }

    /**
     * Enable network simulation with array-based random delays
     */
    public function withNetworkRandomDelay(array $delayRange, array $additionalSettings = []): self
    {
        $settings = array_merge($additionalSettings, ['random_delay' => $delayRange]);
        $this->networkSimulator->enable($settings);

        return $this;
    }

    public function enableNetworkSimulation(array $settings = []): self
    {
        $this->networkSimulator->enable($settings);

        return $this;
    }

    public function disableNetworkSimulation(): self
    {
        $this->networkSimulator->disable();

        return $this;
    }

    /**
     * Simulate poor network conditions
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
     * Simulate fast, reliable network
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
     * Simulate mobile network conditions
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
     * Simulate unstable network with intermittent issues
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

    public function setAutoTempFileManagement(bool $enabled): self
    {
        $this->fileManager->setAutoManagement($enabled);

        return $this;
    }

    public function setStrictMatching(bool $strict): self
    {
        $this->globalSettings['strict_matching'] = $strict;

        return $this;
    }

    public function setRecordRequests(bool $enabled): self
    {
        $this->globalSettings['record_requests'] = $enabled;
        $this->requestRecorder->setRecordRequests($enabled);

        return $this;
    }

    public function setAllowPassthrough(bool $allow): self
    {
        $this->globalSettings['allow_passthrough'] = $allow;

        return $this;
    }

    /**
     * Override the main sendRequest method used by the Request builder.
     * This is the primary entry point for all Request builder requests.
     */
    public function sendRequest(string $url, array $curlOptions, ?CacheConfig $cacheConfig = null, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        return $this->requestExecutor->executeSendRequest(
            $url,
            $curlOptions,
            $this->mockedRequests,
            $this->globalSettings,
            $cacheConfig,
            $retryConfig,
            fn ($url, $curlOptions, $cacheConfig, $retryConfig) => parent::sendRequest($url, $curlOptions, $cacheConfig, $retryConfig)
        );
    }

    public function fetch(string $url, array $options = []): PromiseInterface|CancellablePromiseInterface
    {
        return $this->requestExecutor->executeFetch(
            $url,
            $options,
            $this->mockedRequests,
            $this->globalSettings,
            fn ($url, $options) => parent::fetch($url, $options),
            [$this, 'createStream']
        );
    }

    public function stream(string $url, array $options = [], ?callable $onChunk = null): CancellablePromiseInterface
    {
        $options['stream'] = true;
        if ($onChunk) {
            $options['on_chunk'] = $onChunk;
        }

        return $this->fetch($url, $options);
    }

    public function download(string $url, ?string $destination = null, array $options = []): CancellablePromiseInterface
    {
        if ($destination === null) {
            $destination = $this->fileManager->createTempFile(
                'download_'.uniqid().'.tmp'
            );
        } else {
            $this->fileManager->trackFile($destination);
        }

        $options['download'] = $destination;

        return $this->fetch($url, $options);
    }

    public static function getTempPath(?string $filename = null): string
    {
        return FileManager::getTempPath($filename);
    }

    public function createTempDirectory(string $prefix = 'http_test_'): string
    {
        return $this->fileManager->createTempDirectory($prefix);
    }

    public function createTempFile(?string $filename = null, string $content = ''): string
    {
        return $this->fileManager->createTempFile($filename, $content);
    }

    public function assertRequestMade(string $method, string $url, array $options = []): void
    {
        foreach ($this->requestRecorder->getRequestHistory() as $request) {
            if ($this->requestMatcher->matchesRequest($request, $method, $url, $options)) {
                return;
            }
        }

        throw new MockAssertionException("Expected request not found: {$method} {$url}");
    }

    public function assertNoRequestsMade(): void
    {
        $history = $this->requestRecorder->getRequestHistory();
        if (! empty($history)) {
            throw new MockAssertionException('Expected no requests, but '.count($history).' were made');
        }
    }

    public function assertRequestCount(int $expected): void
    {
        $actual = count($this->requestRecorder->getRequestHistory());
        if ($actual !== $expected) {
            throw new MockAssertionException("Expected {$expected} requests, but {$actual} were made");
        }
    }

    /**
     * Assert that a cookie was sent in the most recent request.
     */
    public function assertCookieSent(string $name): void
    {
        $history = $this->requestRecorder->getRequestHistory();
        if (empty($history)) {
            throw new MockAssertionException('No requests have been made');
        }

        $lastRequest = end($history);
        $this->cookieManager->assertCookieSent($name, $lastRequest->options);
    }

    /**
     * Assert that a cookie exists in the default cookie jar.
     */
    public function assertCookieExists(string $name): void
    {
        $this->cookieManager->assertCookieExists($name);
    }

    /**
     * Assert that a cookie has a specific value.
     */
    public function assertCookieValue(string $name, string $expectedValue): void
    {
        $this->cookieManager->assertCookieValue($name, $expectedValue);
    }

    public function getRequestHistory(): array
    {
        return $this->requestRecorder->getRequestHistory();
    }

    /**
     * Generate global random delay if enabled.
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
