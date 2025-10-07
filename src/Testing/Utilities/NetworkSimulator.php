<?php

namespace Hibla\HttpClient\Testing\Utilities;

/**
 * Simulates various network conditions for testing.
 */
class NetworkSimulator
{
    /**
     * Whether network simulation is enabled.
     */
    private bool $enabled = false;

    /**
     * Network simulation settings.
     *
     * @var array{failure_rate: float, timeout_rate: float, connection_failure_rate: float, default_delay: float|array<float>, timeout_delay: float|array<float>, retryable_failure_rate: float, random_delay: array<float>|null}
     */
    private array $settings = [
        'failure_rate' => 0.0,
        'timeout_rate' => 0.0,
        'connection_failure_rate' => 0.0,
        'default_delay' => 0,
        'timeout_delay' => 30.0,
        'retryable_failure_rate' => 0.0,
        'random_delay' => null,
    ];

    /**
     * Enables network simulation with optional settings.
     *
     * @param array{failure_rate?: float, timeout_rate?: float, connection_failure_rate?: float, default_delay?: float|array<float>, timeout_delay?: float|array<float>, retryable_failure_rate?: float, random_delay?: array<float>|null} $settings
     */
    public function enable(array $settings = []): void
    {
        $this->enabled = true;
        $this->settings = array_merge($this->settings, $settings);
    }

    /**
     * Disables network simulation.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Simulates network conditions and may throw exceptions or modify behavior.
     *
     * @return array{should_timeout: bool, should_fail: bool, error_message: string|null, delay: float}
     */
    public function simulate(): array
    {
        if (! $this->enabled) {
            return [
                'should_timeout' => false,
                'should_fail' => false,
                'error_message' => null,
                'delay' => $this->calculateDelay($this->settings['default_delay']),
            ];
        }

        $result = [
            'should_timeout' => false,
            'should_fail' => false,
            'error_message' => null,
            'delay' => $this->getNetworkDelay(),
        ];

        if (mt_rand() / mt_getrandmax() < $this->settings['timeout_rate']) {
            $result['should_timeout'] = true;
            $result['delay'] = $this->calculateDelay($this->settings['timeout_delay']);
            $result['error_message'] = sprintf(
                'Connection timed out after %.1fs (simulated network timeout)',
                $result['delay']
            );

            return $result;
        }

        if (mt_rand() / mt_getrandmax() < $this->settings['retryable_failure_rate']) {
            $result['should_fail'] = true;
            $retryableErrors = [
                'Connection failed (network simulation)',
                'Could not resolve host (network simulation)',
                'Connection timed out during handshake (network simulation)',
                'SSL connection timeout (network simulation)',
                'Resolving timed out (network simulation)',
            ];
            $result['error_message'] = $retryableErrors[array_rand($retryableErrors)];

            return $result;
        }

        if (mt_rand() / mt_getrandmax() < $this->settings['failure_rate']) {
            $result['should_fail'] = true;
            $result['error_message'] = 'Simulated network failure';

            return $result;
        }

        if (mt_rand() / mt_getrandmax() < $this->settings['connection_failure_rate']) {
            $result['should_fail'] = true;
            $result['error_message'] = 'Connection refused (network simulation)';

            return $result;
        }

        return $result;
    }

    /**
     * Gets network delay, prioritizing random_delay setting over default_delay.
     *
     * @return float Delay in seconds
     */
    private function getNetworkDelay(): float
    {
        if ($this->settings['random_delay'] !== null) {
            return $this->calculateDelay($this->settings['random_delay']);
        }

        return $this->calculateDelay($this->settings['default_delay']);
    }

    /**
     * Calculates delay based on configuration (supports both single values and arrays).
     *
     * @param mixed $delayConfig Delay configuration (float, int, or array)
     * @return float Calculated delay in seconds
     */
    private function calculateDelay($delayConfig): float
    {
        if (is_array($delayConfig)) {
            if (count($delayConfig) === 2 && is_numeric($delayConfig[0]) && is_numeric($delayConfig[1])) {
                $min = is_float($delayConfig[0]) || is_int($delayConfig[0]) ? (float) $delayConfig[0] : 0.0;
                $max = is_float($delayConfig[1]) || is_int($delayConfig[1]) ? (float) $delayConfig[1] : 0.0;

                return $this->generateAggressiveRandomFloat($min, $max);
            } elseif (count($delayConfig) > 0) {
                $randomKey = array_rand($delayConfig);
                $value = $delayConfig[$randomKey];

                return is_float($value) || is_int($value) ? (float) $value : 0.0;
            }

            return 0.0;
        }

        return is_float($delayConfig) || is_int($delayConfig) ? (float) $delayConfig : 0.0;
    }

    /**
     * Generates aggressive random float with high precision for realistic network simulation.
     *
     * @param float $min Minimum value
     * @param float $max Maximum value
     * @return float Random float between min and max
     */
    private function generateAggressiveRandomFloat(float $min, float $max): float
    {
        $precision = 1000000;
        $randomInt = random_int(
            (int) ($min * $precision),
            (int) ($max * $precision)
        );

        return $randomInt / $precision;
    }

    /**
     * Gets the default network delay.
     *
     * @return float Delay in seconds
     */
    public function getDefaultDelay(): float
    {
        return $this->getNetworkDelay();
    }

    /**
     * Gets the timeout delay.
     *
     * @return float Delay in seconds
     */
    public function getTimeoutDelay(): float
    {
        return $this->calculateDelay($this->settings['timeout_delay']);
    }

    /**
     * Sets random delay range for network simulation.
     *
     * @param array<float> $delayRange Array with [min, max] delay values
     */
    public function setRandomDelay(array $delayRange): void
    {
        $this->settings['random_delay'] = $delayRange;
    }
}
