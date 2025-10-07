<?php

use Hibla\HttpClient\Handlers\OptionsBuilderHandler;
use Hibla\HttpClient\ProxyConfig;
use Hibla\HttpClient\Stream;

test('it builds basic GET request options correctly', function () {
    $handler = new OptionsBuilderHandler();
    $stream = Stream::fromString('');

    $options = $handler->buildCurlOptions(
        'GET',
        'https://api.example.com',
        ['Accept' => ['application/json']],
        $stream,
        30, 10, true, 5, true, 'Test-Agent', '1.1', null, null, null, null, []
    );

    expect($options)->toHaveKey(CURLOPT_URL, 'https://api.example.com');
    expect($options)->toHaveKey(CURLOPT_CUSTOMREQUEST, 'GET');
    expect($options)->toHaveKey(CURLOPT_HTTPHEADER, ['Accept: application/json']);
    expect($options)->toHaveKey(CURLOPT_TIMEOUT, 30);
    expect($options)->toHaveKey(CURLOPT_USERAGENT, 'Test-Agent');
});

test('it builds POST request options with a JSON body', function () {
    $handler = new OptionsBuilderHandler();
    $stream = Stream::fromString('{"foo":"bar"}');
    $headers = ['Content-Type' => ['application/json']];

    $options = $handler->buildCurlOptions(
        'POST',
        'https://api.example.com',
        $headers,
        $stream,
        30, 10, true, 5, true, 'Test-Agent', '1.1', null, null, null, null, []
    );

    expect($options)->toHaveKey(CURLOPT_CUSTOMREQUEST, 'POST');
    expect($options)->toHaveKey(CURLOPT_POSTFIELDS, '{"foo":"bar"}');
    expect($options[CURLOPT_HTTPHEADER])->toContain('Content-Type: application/json');
});

test('it configures basic authentication correctly', function () {
    $handler = new OptionsBuilderHandler();
    $stream = Stream::fromString('');
    $auth = ['basic', 'testuser', 'testpass'];

    $options = $handler->buildCurlOptions(
        'GET',
        'https://api.example.com',
        [],
        $stream,
        30, 10, true, 5, true, null, '1.1', null, null, null, $auth, []
    );

    expect($options)->toHaveKey(CURLOPT_USERPWD, 'testuser:testpass');
    expect($options)->toHaveKey(CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
});

test('it configures an HTTP proxy correctly', function () {
    $handler = new OptionsBuilderHandler();
    $stream = Stream::fromString('');
    $proxy = new ProxyConfig('proxy.example.com', 8080, 'user', 'pass');

    $options = $handler->buildCurlOptions(
        'GET',
        'https://api.example.com',
        [],
        $stream,
        30, 10, true, 5, true, null, '1.1', null, null, $proxy, null, []
    );

    expect($options)->toHaveKey(CURLOPT_PROXY, 'proxy.example.com:8080');
    expect($options)->toHaveKey(CURLOPT_PROXYUSERPWD, 'user:pass');
    expect($options)->toHaveKey(CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
});