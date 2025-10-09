<?php

use Hibla\HttpClient\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\HttpClient\Testing\MockedRequest;

describe('DelayCalculator', function () {
    
    it('returns mock delay when it is the highest', function () {
        $calculator = new DelayCalculator();
        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(5.0);

        $result = $calculator->calculateTotalDelay($mock, ['delay' => 2.0], 3.0);

        expect($result)->toBe(5.0);
    });

    it('returns global delay when it is the highest', function () {
        $calculator = new DelayCalculator();
        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(1.0);

        $result = $calculator->calculateTotalDelay($mock, ['delay' => 2.0], 7.0);

        expect($result)->toBe(7.0);
    });

    it('returns network delay when it is the highest', function () {
        $calculator = new DelayCalculator();
        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(1.0);

        $result = $calculator->calculateTotalDelay($mock, ['delay' => 10.0], 3.0);

        expect($result)->toBe(10.0);
    });

    it('returns 0 when all delays are 0', function () {
        $calculator = new DelayCalculator();
        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);

        $result = $calculator->calculateTotalDelay($mock, ['delay' => 0.0], 0.0);

        expect($result)->toBe(0.0);
    });

    it('handles missing delay in network conditions', function () {
        $calculator = new DelayCalculator();
        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(2.0);

        $result = $calculator->calculateTotalDelay($mock, [], 3.0);

        expect($result)->toBe(3.0);
    });

    it('handles negative delays by returning the maximum', function () {
        $calculator = new DelayCalculator();
        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(-1.0);

        $result = $calculator->calculateTotalDelay($mock, ['delay' => -2.0], 5.0);

        expect($result)->toBe(5.0);
    });

    afterEach(function () {
        Mockery::close();
    });
});