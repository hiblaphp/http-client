<?php

use Hibla\HttpClient\Testing\Utilities\Validators\RequestValidator;

describe('RequestValidator', function () {

    describe('validateNotSSERequest', function () {
        it('does not throw when Accept header is not SSE', function () {
            $validator = new RequestValidator();
            $curlOptions = [
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ];

            expect(fn () => $validator->validateNotSSERequest($curlOptions))->not->toThrow(InvalidArgumentException::class);
        });

        it('does not throw when no headers are present', function () {
            $validator = new RequestValidator();
            $curlOptions = [];

            expect(fn () => $validator->validateNotSSERequest($curlOptions))->not->toThrow(InvalidArgumentException::class);
        });

        it('throws when Accept header is text/event-stream', function () {
            $validator = new RequestValidator();
            $curlOptions = [
                CURLOPT_HTTPHEADER => ['Accept: text/event-stream'],
            ];

            expect(fn () => $validator->validateNotSSERequest($curlOptions))
                ->toThrow(InvalidArgumentException::class, 'SSE requests should use')
            ;
        });

        it('throws when SSE header is present with other headers', function () {
            $validator = new RequestValidator();
            $curlOptions = [
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                    'Authorization: Bearer token',
                ],
            ];

            expect(fn () => $validator->validateNotSSERequest($curlOptions))
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('is case insensitive for Accept header detection', function () {
            $validator = new RequestValidator();
            $variations = [
                'accept: text/event-stream',
                'ACCEPT: TEXT/EVENT-STREAM',
                'Accept: Text/Event-Stream',
            ];

            foreach ($variations as $header) {
                $curlOptions = [CURLOPT_HTTPHEADER => [$header]];
                expect(fn () => $validator->validateNotSSERequest($curlOptions))
                    ->toThrow(InvalidArgumentException::class)
                ;
            }
        });

        it('does not throw when HTTPHEADER is not an array', function () {
            $validator = new RequestValidator();
            $curlOptions = [CURLOPT_HTTPHEADER => 'invalid'];

            expect(fn () => $validator->validateNotSSERequest($curlOptions))->not->toThrow(InvalidArgumentException::class);
        });

        it('ignores non-string headers', function () {
            $validator = new RequestValidator();
            $curlOptions = [
                CURLOPT_HTTPHEADER => [123, null, true],
            ];

            expect(fn () => $validator->validateNotSSERequest($curlOptions))->not->toThrow(InvalidArgumentException::class);
        });

        it('handles partial SSE header match', function () {
            $validator = new RequestValidator();
            $curlOptions = [
                CURLOPT_HTTPHEADER => ['X-Custom-Accept: text/event-stream'],
            ];

            expect(fn () => $validator->validateNotSSERequest($curlOptions))
                ->toThrow(InvalidArgumentException::class)
            ;
        });
    });

    describe('isSSERequested', function () {
        it('returns true when sse option is true', function () {
            $validator = new RequestValidator();
            $options = ['sse' => true];

            expect($validator->isSSERequested($options))->toBeTrue();
        });

        it('returns false when sse option is false', function () {
            $validator = new RequestValidator();
            $options = ['sse' => false];

            expect($validator->isSSERequested($options))->toBeFalse();
        });

        it('returns false when sse option is not set', function () {
            $validator = new RequestValidator();
            $options = [];

            expect($validator->isSSERequested($options))->toBeFalse();
        });

        it('returns false when sse option is truthy but not true', function () {
            $validator = new RequestValidator();

            expect($validator->isSSERequested(['sse' => 1]))->toBeFalse()
                ->and($validator->isSSERequested(['sse' => 'true']))->toBeFalse()
                ->and($validator->isSSERequested(['sse' => []]))->toBeFalse()
            ;
        });

        it('handles other options present', function () {
            $validator = new RequestValidator();
            $options = [
                'headers' => ['Authorization' => 'Bearer token'],
                'sse' => true,
                'timeout' => 30,
            ];

            expect($validator->isSSERequested($options))->toBeTrue();
        });
    });

    describe('Edge Cases', function () {
        it('handles empty HTTPHEADER array', function () {
            $validator = new RequestValidator();
            $curlOptions = [CURLOPT_HTTPHEADER => []];

            expect(fn () => $validator->validateNotSSERequest($curlOptions))->not->toThrow(InvalidArgumentException::class);
        });

        it('handles multiple Accept headers with SSE', function () {
            $validator = new RequestValidator();
            $curlOptions = [
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Accept: text/event-stream',
                ],
            ];

            expect(fn () => $validator->validateNotSSERequest($curlOptions))
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('does not match SSE in non-Accept headers', function () {
            $validator = new RequestValidator();
            $curlOptions = [
                CURLOPT_HTTPHEADER => ['Content-Type: text/event-stream'],
            ];

            expect(fn () => $validator->validateNotSSERequest($curlOptions))->not->toThrow(InvalidArgumentException::class);
        });
    });
});
