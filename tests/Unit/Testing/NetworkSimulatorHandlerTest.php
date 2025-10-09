<?php

use Hibla\HttpClient\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\HttpClient\Testing\Utilities\NetworkSimulator;
use Hibla\HttpClient\Testing\TestingHttpHandler;

describe('NetworkSimulationHandler', function () {
    
    describe('simulate', function () {
        it('returns should_fail false and delay 0 when no failure', function () {
            $simulator = Mockery::mock(NetworkSimulator::class);
            $simulator->shouldReceive('simulate')->andReturn([
                'should_fail' => false,
                'should_timeout' => false,
                'error_message' => null,
                'delay' => 0.0,
            ]);

            $handler = new NetworkSimulationHandler($simulator);
            $result = $handler->simulate();

            expect($result['should_fail'])->toBeFalse()
                ->and($result['delay'])->toBe(0.0)
                ->and($result)->not->toHaveKey('error_message');
        });

        it('returns should_fail true with error message on failure', function () {
            $simulator = Mockery::mock(NetworkSimulator::class);
            $simulator->shouldReceive('simulate')->andReturn([
                'should_fail' => true,
                'should_timeout' => false,
                'error_message' => 'Network error',
                'delay' => 2.5,
            ]);

            $handler = new NetworkSimulationHandler($simulator);
            $result = $handler->simulate();

            expect($result['should_fail'])->toBeTrue()
                ->and($result['delay'])->toBe(2.5)
                ->and($result['error_message'])->toBe('Network error');
        });

        it('includes delay from simulator', function () {
            $simulator = Mockery::mock(NetworkSimulator::class);
            $simulator->shouldReceive('simulate')->andReturn([
                'should_fail' => false,
                'delay' => 3.5,
            ]);

            $handler = new NetworkSimulationHandler($simulator);
            $result = $handler->simulate();

            expect($result['delay'])->toBe(3.5);
        });

        it('handles missing delay in simulator result', function () {
            $simulator = Mockery::mock(NetworkSimulator::class);
            $simulator->shouldReceive('simulate')->andReturn([
                'should_fail' => false,
            ]);

            $handler = new NetworkSimulationHandler($simulator);
            $result = $handler->simulate();

            expect($result['delay'])->toBe(0.0);
        });

        it('does not include error_message when not failing', function () {
            $simulator = Mockery::mock(NetworkSimulator::class);
            $simulator->shouldReceive('simulate')->andReturn([
                'should_fail' => false,
                'error_message' => 'Should not appear',
                'delay' => 1.0,
            ]);

            $handler = new NetworkSimulationHandler($simulator);
            $result = $handler->simulate();

            expect($result)->not->toHaveKey('error_message');
        });
    });

    describe('generateGlobalRandomDelay', function () {
        it('returns 0 when handler is null', function () {
            $simulator = Mockery::mock(NetworkSimulator::class);
            $handler = new NetworkSimulationHandler($simulator, null);

            expect($handler->generateGlobalRandomDelay())->toBe(0.0);
        });

        it('returns delay from testing handler', function () {
            $simulator = Mockery::mock(NetworkSimulator::class);
            $testingHandler = Mockery::mock(TestingHttpHandler::class);
            $testingHandler->shouldReceive('generateGlobalRandomDelay')->andReturn(2.5);

            $handler = new NetworkSimulationHandler($simulator, $testingHandler);

            expect($handler->generateGlobalRandomDelay())->toBe(2.5);
        });
    });

    afterEach(function () {
        Mockery::close();
    });
});