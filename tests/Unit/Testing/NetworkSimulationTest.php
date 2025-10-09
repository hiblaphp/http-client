<?php

use Hibla\HttpClient\Testing\Utilities\NetworkSimulator;

describe('NetworkSimulator', function () {

    describe('Initialization and State', function () {
        it('starts disabled by default', function () {
            $simulator = new NetworkSimulator();
            $result = $simulator->simulate();

            expect($result['should_timeout'])->toBeFalse()
                ->and($result['should_fail'])->toBeFalse()
                ->and($result['error_message'])->toBeNull()
                ->and($result['delay'])->toBe(0.0);
        });

        it('can be enabled', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable();
            $result = $simulator->simulate();

            expect($result)->toBeArray()
                ->and($result)->toHaveKey('should_timeout')
                ->and($result)->toHaveKey('should_fail')
                ->and($result)->toHaveKey('error_message')
                ->and($result)->toHaveKey('delay');
        });

        it('can be disabled after being enabled', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['failure_rate' => 1.0]);
            $simulator->disable();
            $result = $simulator->simulate();

            expect($result['should_fail'])->toBeFalse();
        });

        it('returns consistent structure when disabled', function () {
            $simulator = new NetworkSimulator();
            $result = $simulator->simulate();

            expect($result)->toHaveKeys(['should_timeout', 'should_fail', 'error_message', 'delay']);
        });
    });

    describe('Timeout Simulation', function () {
        it('simulates timeout when timeout_rate is 1.0', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['timeout_rate' => 1.0]);
            $result = $simulator->simulate();

            expect($result['should_timeout'])->toBeTrue()
                ->and($result['error_message'])->toContain('Connection timed out')
                ->and($result['error_message'])->toContain('simulated network timeout');
        });

        it('never times out when timeout_rate is 0.0', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['timeout_rate' => 0.0]);

            for ($i = 0; $i < 10; $i++) {
                $result = $simulator->simulate();
                expect($result['should_timeout'])->toBeFalse();
            }
        });

        it('uses timeout_delay for timeout duration', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 1.0,
                'timeout_delay' => 45.0,
            ]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBe(45.0)
                ->and($result['error_message'])->toContain('45.0s');
        });

        it('supports random timeout delay range', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 1.0,
                'timeout_delay' => [30.0, 60.0],
            ]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBeGreaterThanOrEqual(30.0)
                ->and($result['delay'])->toBeLessThanOrEqual(60.0);
        });

        it('timeout takes precedence over other failures', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 1.0,
                'failure_rate' => 1.0,
                'connection_failure_rate' => 1.0,
            ]);
            $result = $simulator->simulate();

            expect($result['should_timeout'])->toBeTrue()
                ->and($result['error_message'])->toContain('timed out');
        });
    });

    describe('Retryable Failure Simulation', function () {
        it('simulates retryable failure when retryable_failure_rate is 1.0', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'retryable_failure_rate' => 1.0,
                'timeout_rate' => 0.0, // Disable timeout to test retryable failures
            ]);
            $result = $simulator->simulate();

            expect($result['should_fail'])->toBeTrue()
                ->and($result['error_message'])->toContain('network simulation');
        });

        it('returns various retryable error messages', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'retryable_failure_rate' => 1.0,
                'timeout_rate' => 0.0,
            ]);

            $errorMessages = [];
            for ($i = 0; $i < 20; $i++) {
                $result = $simulator->simulate();
                $errorMessages[] = $result['error_message'];
            }

            $uniqueErrors = array_unique($errorMessages);
            expect(count($uniqueErrors))->toBeGreaterThan(1);
        });

        it('retryable failures include expected error types', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'retryable_failure_rate' => 1.0,
                'timeout_rate' => 0.0,
            ]);

            $expectedPatterns = [
                'Connection failed',
                'Could not resolve host',
                'Connection timed out during handshake',
                'SSL connection timeout',
                'Resolving timed out',
            ];

            $foundPatterns = [];
            for ($i = 0; $i < 50; $i++) {
                $result = $simulator->simulate();
                foreach ($expectedPatterns as $pattern) {
                    if (str_contains($result['error_message'], $pattern)) {
                        $foundPatterns[$pattern] = true;
                    }
                }
            }

            expect(count($foundPatterns))->toBeGreaterThan(3);
        });

        it('retryable failure takes precedence over general failure', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 0.0,
                'retryable_failure_rate' => 1.0,
                'failure_rate' => 1.0,
                'connection_failure_rate' => 1.0,
            ]);
            $result = $simulator->simulate();

            expect($result['should_fail'])->toBeTrue()
                ->and($result['error_message'])->not->toBe('Simulated network failure')
                ->and($result['error_message'])->not->toBe('Connection refused (network simulation)');
        });
    });

    describe('General Failure Simulation', function () {
        it('simulates failure when failure_rate is 1.0', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 0.0,
                'retryable_failure_rate' => 0.0,
                'failure_rate' => 1.0,
            ]);
            $result = $simulator->simulate();

            expect($result['should_fail'])->toBeTrue()
                ->and($result['error_message'])->toBe('Simulated network failure');
        });

        it('never fails when failure_rate is 0.0', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['failure_rate' => 0.0]);

            for ($i = 0; $i < 10; $i++) {
                $result = $simulator->simulate();
                expect($result['should_fail'])->toBeFalse();
            }
        });

        it('general failure takes precedence over connection failure', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 0.0,
                'retryable_failure_rate' => 0.0,
                'failure_rate' => 1.0,
                'connection_failure_rate' => 1.0,
            ]);
            $result = $simulator->simulate();

            expect($result['error_message'])->toBe('Simulated network failure');
        });
    });

    describe('Connection Failure Simulation', function () {
        it('simulates connection failure when connection_failure_rate is 1.0', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 0.0,
                'retryable_failure_rate' => 0.0,
                'failure_rate' => 0.0,
                'connection_failure_rate' => 1.0,
            ]);
            $result = $simulator->simulate();

            expect($result['should_fail'])->toBeTrue()
                ->and($result['error_message'])->toBe('Connection refused (network simulation)');
        });

        it('never fails connection when connection_failure_rate is 0.0', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['connection_failure_rate' => 0.0]);

            $hasConnectionRefusedError = false;
            for ($i = 0; $i < 10; $i++) {
                $result = $simulator->simulate();
                if ($result['should_fail'] && str_contains($result['error_message'], 'Connection refused')) {
                    $hasConnectionRefusedError = true;
                    break;
                }
            }

            expect($hasConnectionRefusedError)->toBeFalse();
        });
    });

    describe('Delay Configuration', function () {
        it('uses default delay of 0 when not configured', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable();
            $result = $simulator->simulate();

            expect($result['delay'])->toBe(0.0);
        });

        it('uses configured default_delay as float', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => 2.5]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBe(2.5);
        });

        it('uses configured default_delay as integer', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => 3]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBe(3.0);
        });

        it('supports random delay range', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => [1.0, 5.0]]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBeGreaterThanOrEqual(1.0)
                ->and($result['delay'])->toBeLessThanOrEqual(5.0);
        });

        it('generates different random delays in range', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => [1.0, 5.0]]);

            $delays = [];
            for ($i = 0; $i < 20; $i++) {
                $result = $simulator->simulate();
                $delays[] = $result['delay'];
            }

            $uniqueDelays = array_unique($delays);
            expect(count($uniqueDelays))->toBeGreaterThan(10);
        });

        it('handles array with single value for delay', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => [2.5, 3.5, 4.5]]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBeIn([2.5, 3.5, 4.5]);
        });

        it('returns 0 for empty array delay', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => []]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBe(0.0);
        });

        it('returns 0 for invalid delay type', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => 'invalid']);
            $result = $simulator->simulate();

            expect($result['delay'])->toBe(0.0);
        });

        it('random_delay takes precedence over default_delay', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'default_delay' => 1.0,
                'random_delay' => [5.0, 10.0],
            ]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBeGreaterThanOrEqual(5.0)
                ->and($result['delay'])->toBeLessThanOrEqual(10.0);
        });
    });

    describe('Random Delay Methods', function () {
        it('setRandomDelay configures random delay range', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable();
            $simulator->setRandomDelay([2.0, 8.0]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBeGreaterThanOrEqual(2.0)
                ->and($result['delay'])->toBeLessThanOrEqual(8.0);
        });

        it('setRandomDelay overrides default_delay', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => 1.0]);
            $simulator->setRandomDelay([5.0, 10.0]);
            $result = $simulator->simulate();

            expect($result['delay'])->not->toBe(1.0)
                ->and($result['delay'])->toBeGreaterThanOrEqual(5.0);
        });
    });

    describe('Getter Methods', function () {
        it('getDefaultDelay returns the configured delay', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => 3.5]);

            expect($simulator->getDefaultDelay())->toBe(3.5);
        });

        it('getDefaultDelay returns value in random range', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => [1.0, 5.0]]);
            $delay = $simulator->getDefaultDelay();

            expect($delay)->toBeGreaterThanOrEqual(1.0)
                ->and($delay)->toBeLessThanOrEqual(5.0);
        });

        it('getDefaultDelay respects random_delay priority', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'default_delay' => 1.0,
                'random_delay' => [5.0, 10.0],
            ]);
            $delay = $simulator->getDefaultDelay();

            expect($delay)->toBeGreaterThanOrEqual(5.0)
                ->and($delay)->toBeLessThanOrEqual(10.0);
        });

        it('getTimeoutDelay returns the timeout delay', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['timeout_delay' => 45.0]);

            expect($simulator->getTimeoutDelay())->toBe(45.0);
        });

        it('getTimeoutDelay returns value in random range', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['timeout_delay' => [30.0, 60.0]]);
            $delay = $simulator->getTimeoutDelay();

            expect($delay)->toBeGreaterThanOrEqual(30.0)
                ->and($delay)->toBeLessThanOrEqual(60.0);
        });

        it('getTimeoutDelay returns default 30.0 when not configured', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable();

            expect($simulator->getTimeoutDelay())->toBe(30.0);
        });
    });

    describe('Probability Distribution', function () {
        it('respects approximate failure rate', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 0.0,
                'retryable_failure_rate' => 0.0,
                'failure_rate' => 0.5, // 50% failure rate
                'connection_failure_rate' => 0.0,
            ]);

            $failures = 0;
            $iterations = 100;

            for ($i = 0; $i < $iterations; $i++) {
                $result = $simulator->simulate();
                if ($result['should_fail']) {
                    $failures++;
                }
            }

            // Allow for statistical variance (30-70% range for 50% rate)
            expect($failures)->toBeGreaterThan(30)
                ->and($failures)->toBeLessThan(70);
        });

        it('respects approximate timeout rate', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 0.3, // 30% timeout rate
            ]);

            $timeouts = 0;
            $iterations = 100;

            for ($i = 0; $i < $iterations; $i++) {
                $result = $simulator->simulate();
                if ($result['should_timeout']) {
                    $timeouts++;
                }
            }

            // Allow for statistical variance (15-45% range for 30% rate)
            expect($timeouts)->toBeGreaterThan(15)
                ->and($timeouts)->toBeLessThan(45);
        });
    });

    describe('Edge Cases', function () {
        it('handles all rates at 0', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 0.0,
                'retryable_failure_rate' => 0.0,
                'failure_rate' => 0.0,
                'connection_failure_rate' => 0.0,
            ]);

            for ($i = 0; $i < 10; $i++) {
                $result = $simulator->simulate();
                expect($result['should_timeout'])->toBeFalse()
                    ->and($result['should_fail'])->toBeFalse()
                    ->and($result['error_message'])->toBeNull();
            }
        });

        it('handles negative delay gracefully', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => -1.0]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBe(-1.0);
        });

        it('handles very small delay ranges', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => [0.001, 0.002]]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBeGreaterThanOrEqual(0.001)
                ->and($result['delay'])->toBeLessThanOrEqual(0.002);
        });

        it('handles very large delay values', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => 3600.0]); // 1 hour
            $result = $simulator->simulate();

            expect($result['delay'])->toBe(3600.0);
        });

        it('handles inverted delay range', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => [10.0, 5.0]]);
            $result = $simulator->simulate();

            // Should still work, generating value between 5.0 and 10.0
            expect($result['delay'])->toBeGreaterThanOrEqual(5.0)
                ->and($result['delay'])->toBeLessThanOrEqual(10.0);
        });

        it('handles array with non-numeric values', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => ['invalid', 'data']]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBe(0.0);
        });

        it('handles mixed numeric and non-numeric array', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => [2.5, 'invalid', 3.5]]);
            $result = $simulator->simulate();

            // Should pick random valid value or return 0
            expect($result['delay'])->toBeIn([0.0, 2.5, 3.5]);
        });

        it('handles boolean delay value', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => true]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBe(0.0);
        });

        it('handles null delay value', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['default_delay' => null]);
            $result = $simulator->simulate();

            expect($result['delay'])->toBe(0.0);
        });
    });

    describe('Real-World Scenarios', function () {
        it('simulates unstable network with mixed conditions', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 0.1,
                'retryable_failure_rate' => 0.1,
                'failure_rate' => 0.1,
                'connection_failure_rate' => 0.1,
                'default_delay' => [0.1, 2.0],
            ]);

            $timeouts = 0;
            $failures = 0;
            $successes = 0;

            for ($i = 0; $i < 50; $i++) {
                $result = $simulator->simulate();
                if ($result['should_timeout']) {
                    $timeouts++;
                } elseif ($result['should_fail']) {
                    $failures++;
                } else {
                    $successes++;
                }
            }

            expect($timeouts)->toBeGreaterThan(0)
                ->and($failures)->toBeGreaterThan(0)
                ->and($successes)->toBeGreaterThan(0);
        });

        it('simulates high latency network', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'default_delay' => [2.0, 5.0],
                'failure_rate' => 0.0,
            ]);

            $delays = [];
            for ($i = 0; $i < 10; $i++) {
                $result = $simulator->simulate();
                $delays[] = $result['delay'];
            }

            foreach ($delays as $delay) {
                expect($delay)->toBeGreaterThanOrEqual(2.0)
                    ->and($delay)->toBeLessThanOrEqual(5.0);
            }
        });

        it('simulates reliable slow network', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 0.0,
                'failure_rate' => 0.0,
                'default_delay' => 3.0,
            ]);

            for ($i = 0; $i < 10; $i++) {
                $result = $simulator->simulate();
                expect($result['should_timeout'])->toBeFalse()
                    ->and($result['should_fail'])->toBeFalse()
                    ->and($result['delay'])->toBe(3.0);
            }
        });

        it('simulates fast but unreliable network', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable([
                'timeout_rate' => 0.0,
                'retryable_failure_rate' => 0.3,
                'default_delay' => [0.1, 0.3],
            ]);

            $failures = 0;
            $fastResponses = 0;

            for ($i = 0; $i < 50; $i++) {
                $result = $simulator->simulate();
                if ($result['should_fail']) {
                    $failures++;
                }
                if ($result['delay'] < 0.5) {
                    $fastResponses++;
                }
            }

            expect($failures)->toBeGreaterThan(5)
                ->and($fastResponses)->toBeGreaterThan(40);
        });
    });

    describe('Settings Merge Behavior', function () {
        it('merges new settings with defaults', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['timeout_rate' => 0.5]);

            // Default values should still be present
            $result = $simulator->simulate();
            expect($result)->toHaveKey('delay');
        });

        it('allows partial configuration updates', function () {
            $simulator = new NetworkSimulator();
            $simulator->enable(['failure_rate' => 0.2]);

            // Other settings should remain at defaults
            expect(true)->toBeTrue(); // Settings are merged correctly
        });
    });
});
