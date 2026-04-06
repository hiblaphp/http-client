<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Testing\Exceptions\UnexpectedRequestException;
use Hibla\HttpClient\Testing\Utilities\RecordedRequest;

use function Hibla\await;

describe('Mock Closure Matching', function () {

    beforeEach(fn () => Http::startTesting());
    afterEach(fn () => Http::stopTesting());

    it('matches a request based on a custom closure', function () {
        Http::mock('POST')
            ->url('https://api.test.com/orders')
            ->expect(function (RecordedRequest $request) {
                $json = $request->getJson();

                return isset($json['total']) && $json['total'] === 150;
            })
            ->respondWithStatus(200)
            ->respondJson(['status' => 'accepted'])
            ->register()
        ;

        try {
            await(Http::post('https://api.test.com/orders', ['total' => 50]));
            expect(true)->toBeFalse();
        } catch (UnexpectedRequestException $e) {
            expect($e->getMessage())->toContain('No mock matched');
        }

        $response = await(Http::post('https://api.test.com/orders', ['total' => 150]));

        expect($response->status())->toBe(200)
            ->and($response->json('status'))->toBe('accepted')
        ;
    });

    it('can combine closure matching with standard matchers', function () {
        Http::mock('GET')
            ->url('https://api.test.com/secure/*')
            ->expect(fn (RecordedRequest $request) => $request->hasHeader('X-Custom-Auth'))
            ->respondWithStatus(200)
            ->register()
        ;

        try {
            await(Http::get('https://api.test.com/public/data'));
            expect(true)->toBeFalse();
        } catch (UnexpectedRequestException $e) {
            expect(true)->toBeTrue();
        }

        try {
            await(Http::get('https://api.test.com/secure/data'));
            expect(true)->toBeFalse();
        } catch (UnexpectedRequestException $e) {
            expect(true)->toBeTrue();
        }

        $response = await(Http::request()->withHeader('X-Custom-Auth', '1')->get('https://api.test.com/secure/data'));

        expect($response->status())->toBe(200);
    });
});
