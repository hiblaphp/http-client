<?php

declare(strict_types=1);

use Hibla\HttpClient\Builders\CurlOptionsBuilder;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\ValueObjects\ClientOptions;
use Hibla\HttpClient\ValueObjects\ProxyConfig;

test('it builds basic GET request options correctly', function () {
    $builder = new CurlOptionsBuilder();
    $stream = Stream::fromString('');

    $clientOptions = new ClientOptions(
        method: 'GET',
        url: 'https://api.example.com',
        headers: ['Accept' => ['application/json']],
        body: $stream,
        timeout: 30,
        connectTimeout: 10,
        followRedirects: true,
        maxRedirects: 5,
        verifySSL: true,
        userAgent: 'Test-Agent',
        protocol: '1.1'
    );

    $options = $builder->build($clientOptions);

    expect($options)->toHaveKey(CURLOPT_URL, 'https://api.example.com');
    expect($options)->toHaveKey(CURLOPT_CUSTOMREQUEST, 'GET');
    expect($options)->toHaveKey(CURLOPT_HTTPHEADER, ['Accept: application/json']);
    expect($options)->toHaveKey(CURLOPT_TIMEOUT, 30);
    expect($options)->toHaveKey(CURLOPT_USERAGENT, 'Test-Agent');
});

test('it builds POST request options with a JSON body', function () {
    $builder = new CurlOptionsBuilder();
    $stream = Stream::fromString('{"foo":"bar"}');
    $headers = ['Content-Type' => ['application/json']];

    $clientOptions = new ClientOptions(
        method: 'POST',
        url: 'https://api.example.com',
        headers: $headers,
        body: $stream,
        timeout: 30,
        connectTimeout: 10,
        followRedirects: true,
        maxRedirects: 5,
        verifySSL: true,
        userAgent: 'Test-Agent',
        protocol: '1.1'
    );

    $options = $builder->build($clientOptions);

    expect($options)->toHaveKey(CURLOPT_CUSTOMREQUEST, 'POST');
    expect($options)->toHaveKey(CURLOPT_POSTFIELDS, '{"foo":"bar"}');
    expect($options[CURLOPT_HTTPHEADER])->toContain('Content-Type: application/json');
});

test('it configures basic authentication correctly', function () {
    $builder = new CurlOptionsBuilder();
    $stream = Stream::fromString('');
    $auth = ['basic', 'testuser', 'testpass'];

    $clientOptions = new ClientOptions(
        method: 'GET',
        url: 'https://api.example.com',
        headers: [],
        body: $stream,
        timeout: 30,
        connectTimeout: 10,
        followRedirects: true,
        maxRedirects: 5,
        verifySSL: true,
        userAgent: null,
        protocol: '1.1',
        auth: $auth
    );

    $options = $builder->build($clientOptions);

    expect($options)->toHaveKey(CURLOPT_USERPWD, 'testuser:testpass');
    expect($options)->toHaveKey(CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
});

test('it configures an HTTP proxy correctly', function () {
    $builder = new CurlOptionsBuilder();
    $stream = Stream::fromString('');
    $proxy = new ProxyConfig('proxy.example.com', 8080, 'user', 'pass');

    $clientOptions = new ClientOptions(
        method: 'GET',
        url: 'https://api.example.com',
        headers: [],
        body: $stream,
        timeout: 30,
        connectTimeout: 10,
        followRedirects: true,
        maxRedirects: 5,
        verifySSL: true,
        userAgent: null,
        protocol: '1.1',
        proxyConfig: $proxy
    );

    $options = $builder->build($clientOptions);

    expect($options)->toHaveKey(CURLOPT_PROXY, 'proxy.example.com:8080');
    expect($options)->toHaveKey(CURLOPT_PROXYUSERPWD, 'user:pass');
    expect($options)->toHaveKey(CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
});
