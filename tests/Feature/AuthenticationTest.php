<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Tests\Fixtures\HttpBin;

use function Hibla\await;

describe('Authentication', function () {
    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    describe('Bearer token', function () {
        it('sends a bearer token in the Authorization header', function () {
            $response = await(
                Http::client()
                    ->withToken('my-secret-token')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toBe('Bearer my-secret-token');
        });

        it('sends a custom token type', function () {
            $response = await(
                Http::client()
                    ->withToken('my-api-key', 'Token')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toBe('Token my-api-key');
        });

        it('overwrites a previously set token', function () {
            $response = await(
                Http::client()
                    ->withToken('old-token')
                    ->withToken('new-token')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toBe('Bearer new-token');
        });

        it('sends a bearer token alongside other headers', function () {
            $response = await(
                Http::client()
                    ->withToken('my-secret-token')
                    ->withHeader('X-Request-ID', 'abc-123')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toBe('Bearer my-secret-token');
            expect($response->json('headers.X-Request-Id.0'))->toBe('abc-123');
        });

        it('authenticates against a protected bearer endpoint', function () {
            $response = await(
                Http::client()
                    ->withToken('valid-token')
                    ->get(HttpBin::url('/bearer'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('authenticated'))->toBeTrue();
            expect($response->json('token'))->toBe('valid-token');
        });
    });

    describe('Basic auth', function () {
        it('sends basic auth credentials', function () {
            $response = await(
                Http::client()
                    ->withBasicAuth('user', 'pass')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toStartWith('Basic ');
        });

        it('encodes credentials correctly', function () {
            $response = await(
                Http::client()
                    ->withBasicAuth('user', 'pass')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();

            $authHeader = $response->json('headers.Authorization.0');
            $encoded = base64_decode(str_replace('Basic ', '', $authHeader));

            expect($encoded)->toBe('user:pass');
        });

        it('authenticates against a protected basic auth endpoint', function () {
            $response = await(
                Http::client()
                    ->withBasicAuth('myuser', 'mypassword')
                    ->get(HttpBin::url('/basic-auth/myuser/mypassword'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('authenticated'))->toBeTrue();
            expect($response->json('user'))->toBe('myuser');
        });

        it('returns 401 when basic auth credentials are wrong', function () {
            $response = await(
                Http::client()
                    ->withBasicAuth('user', 'wrongpassword')
                    ->get(HttpBin::url('/basic-auth/user/correctpassword'))
            );

            expect($response->status())->toBe(401);
            expect($response->successful())->toBeFalse();
            expect($response->clientError())->toBeTrue();
        });

        it('handles special characters in credentials', function () {
            $response = await(
                Http::client()
                    ->withBasicAuth('user@domain.com', 'p@$$w0rd!')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();

            $authHeader = $response->json('headers.Authorization.0');
            $encoded = base64_decode(str_replace('Basic ', '', $authHeader));

            expect($encoded)->toBe('user@domain.com:p@$$w0rd!');
        });

        it('overwrites previously set basic auth', function () {
            $response = await(
                Http::client()
                    ->withBasicAuth('first', 'credentials')
                    ->withBasicAuth('myuser', 'mypassword')
                    ->get(HttpBin::url('/basic-auth/myuser/mypassword'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('authenticated'))->toBeTrue();
        });
    });

    describe('Digest auth', function () {
        it('sends digest auth credentials in the Authorization header', function () {
            $response = await(
                Http::client()
                    ->withDigestAuth('user', 'pass')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
        });

        it('authenticates against a protected digest auth endpoint', function () {
            $response = await(
                Http::client()
                    ->withDigestAuth('myuser', 'mypassword')
                    ->get(HttpBin::url('/digest-auth/auth/myuser/mypassword'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('authenticated'))->toBeTrue();
            expect($response->json('user'))->toBe('myuser');
        });

        it('returns 401 when digest auth credentials are wrong', function () {
            $response = await(
                Http::client()
                    ->withDigestAuth('user', 'wrongpassword')
                    ->get(HttpBin::url('/digest-auth/auth/user/correctpassword'))
            );

            expect($response->status())->toBe(401);
            expect($response->successful())->toBeFalse();
            expect($response->clientError())->toBeTrue();
        });
    });

    describe('auth precedence and isolation', function () {
        it('last auth method wins when chaining different auth types', function () {
            $basicLast = await(
                Http::client()
                    ->withToken('some-token')
                    ->withBasicAuth('myuser', 'mypassword')
                    ->get(HttpBin::url('/get'))
            );

            expect($basicLast->json('headers.Authorization.0'))->toStartWith('Basic ');
            expect($basicLast->json('headers.Authorization.0'))->not->toStartWith('Bearer ');

            $tokenLast = await(
                Http::client()
                    ->withBasicAuth('myuser', 'mypassword')
                    ->withToken('some-token')
                    ->get(HttpBin::url('/get'))
            );

            expect($tokenLast->json('headers.Authorization.0'))->toBe('Bearer some-token');
        });

        it('does not leak auth between independent requests', function () {
            $authed = await(
                Http::client()
                    ->withToken('secret')
                    ->get(HttpBin::url('/get'))
            );

            $unauthed = await(
                Http::client()
                    ->get(HttpBin::url('/get'))
            );

            expect($authed->json('headers.Authorization.0'))->toBe('Bearer secret');
            expect($unauthed->json('headers.Authorization.0'))->toBeNull();
        });

        it('does not leak auth when branching from a shared base client', function () {
            $base = Http::client()->withToken('base-token');

            $withAuth = await($base->get(HttpBin::url('/get')));
            $withoutAuth = await(Http::client()->get(HttpBin::url('/get')));

            expect($withAuth->json('headers.Authorization.0'))->toBe('Bearer base-token');
            expect($withoutAuth->json('headers.Authorization.0'))->toBeNull();
        });
    });
});
