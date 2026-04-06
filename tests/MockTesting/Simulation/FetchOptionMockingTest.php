<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Fetch API to cURL Option Mapping', function () {
    test('it maps redirect and timeout options correctly', function () {
        Http::mock('GET')->url('/fetch-map')->respondWithStatus(200)->register();

        Http::fetch('/fetch-map', [
            'follow_redirects' => true,
            'max_redirects' => 12,
            'timeout' => 45,
            'connect_timeout' => 10,
            'verify_ssl' => false
        ])->wait();

        $options = Http::getLastRequest()->getOptions();

        expect($options[CURLOPT_FOLLOWLOCATION])->toBeTrue()
            ->and($options[CURLOPT_MAXREDIRS])->toBe(12)
            ->and($options[CURLOPT_TIMEOUT])->toBe(45)
            ->and($options[CURLOPT_CONNECTTIMEOUT])->toBe(10)
            ->and($options[CURLOPT_SSL_VERIFYPEER])->toBeFalse();
    });

    test('it maps auth arrays to cURL auth options', function () {
        Http::mock('GET')->url('/fetch-auth')->respondWithStatus(200)->register();

        Http::fetch('/fetch-auth', [
            'auth' => [
                'basic' => [
                    'username' => 'admin',
                    'password' => 'password123'
                ]
            ]
        ])->wait();

        $options = Http::getLastRequest()->getOptions();

        expect($options[CURLOPT_HTTPAUTH])->toBe(CURLAUTH_BASIC)
            ->and($options[CURLOPT_USERPWD])->toBe('admin:password123');
    });

    test('it maps protocol versions correctly', function () {
        Http::mock('GET')->url('/fetch-proto')->respondWithStatus(200)->register();

        Http::fetch('/fetch-proto', [
            'http_version' => '1.1'
        ])->wait();

        $options = Http::getLastRequest()->getOptions();
        expect($options[CURLOPT_HTTP_VERSION])->toBe(CURL_HTTP_VERSION_1_1);

        Http::fetch('/fetch-proto', [
            'http_version' => '2.0'
        ])->wait();

        $options = Http::getLastRequest()->getOptions();
        expect($options[CURLOPT_HTTP_VERSION])->toBe(CURL_HTTP_VERSION_2TLS);
    });

    test('it handles proxy string mapping in fetch', function () {
        Http::mock('GET')->url('/fetch-proxy')->respondWithStatus(200)->register();

        Http::fetch('/fetch-proxy', [
            'proxy' => 'http://user:pass@myproxy.com:3128'
        ])->wait();

        $options = Http::getLastRequest()->getOptions();

        expect($options[CURLOPT_PROXY])->toBe('myproxy.com:3128')
            ->and($options[CURLOPT_PROXYUSERPWD])->toBe('user:pass')
            ->and($options[CURLOPT_PROXYTYPE])->toBe(CURLPROXY_HTTP);
    });
});