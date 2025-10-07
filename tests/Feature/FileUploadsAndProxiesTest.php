<?php

use Hibla\HttpClient\Http;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('File Uploads', function () {
    it('correctly prepares a single file for upload', function () {
        Http::mock()->url('/upload')->respondWith('OK')->register();

        $filePath = Http::getTestingHandler()->createTempFile('test.txt', 'file content');

        Http::withFile('attachment', $filePath, 'custom.txt')->post('/upload')->await();

        $lastRequest = Http::getLastRequest();
        $options = $lastRequest->getOptions();
        $postFields = $options[CURLOPT_POSTFIELDS] ?? null;

        expect(is_array($postFields))->toBeTrue();
        expect($postFields['attachment']['name'])->toBe('attachment');
        expect($postFields['attachment']['filename'])->toBe('custom.txt');
        expect(is_resource($postFields['attachment']['contents']))->toBeTrue();
    });

    it('correctly prepares a multipart request with data and files', function () {
        Http::mock()->url('/multipart')->respondWith('OK')->register();
        $filePath = Http::getTestingHandler()->createTempFile('test.txt', 'file content');

        $data = [
            'field1' => 'value1',
            'field2' => 'value2',
        ];
        $files = [
            'upload' => $filePath,
        ];

        Http::multipartWithFiles($data, $files)->post('/multipart')->await();

        $lastRequest = Http::getLastRequest();
        $options = $lastRequest->getOptions();
        $postFields = $options[CURLOPT_POSTFIELDS] ?? null;

        expect(is_array($postFields))->toBeTrue();
        expect($postFields['field1'])->toBe('value1');
        expect($postFields['upload']['name'])->toBe('upload');
    });
});

describe('Proxy Configuration', function () {
    it('configures an HTTP proxy with authentication', function () {
        Http::mock()->url('/proxied')->respondWith('OK')->register();

        Http::withProxy('proxy.example.com', 8080, 'user', 'pass')
            ->get('/proxied')
            ->await();

        $lastRequest = Http::getLastRequest();
        $options = $lastRequest->getOptions();

        expect($options[CURLOPT_PROXY])->toBe('proxy.example.com:8080');
        expect($options[CURLOPT_PROXYUSERPWD])->toBe('user:pass');
        expect($options[CURLOPT_PROXYTYPE])->toBe(CURLPROXY_HTTP);
    });

    it('configures a SOCKS5 proxy', function () {
        Http::mock()->url('/proxied')->respondWith('OK')->register();

        Http::withSocks5Proxy('socks.example.com', 1080)
            ->get('/proxied')
            ->await();

        $lastRequest = Http::getLastRequest();
        $options = $lastRequest->getOptions();

        expect($options[CURLOPT_PROXY])->toBe('socks.example.com:1080');
        expect($options[CURLOPT_PROXYTYPE])->toBe(CURLPROXY_SOCKS5);
    });
});