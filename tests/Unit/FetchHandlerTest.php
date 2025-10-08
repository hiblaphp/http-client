<?php

use Hibla\HttpClient\Handlers\FetchHandler;
use Hibla\HttpClient\ProxyConfig;

test('it normalizes basic method and headers', function () {
    $handler = new FetchHandler();
    $options = ['method' => 'POST', 'headers' => ['Accept' => 'application/json']];
    $curlOpts = $handler->normalizeFetchOptions('https://example.com', $options);

    expect($curlOpts[CURLOPT_CUSTOMREQUEST])->toBe('POST');
    expect($curlOpts[CURLOPT_HTTPHEADER])->toContain('Accept: application/json');
});

test('it normalizes a JSON body', function () {
    $handler = new FetchHandler();
    $options = ['json' => ['foo' => 'bar']];
    $curlOpts = $handler->normalizeFetchOptions('https://example.com', $options);

    expect($curlOpts[CURLOPT_POSTFIELDS])->toBe('{"foo":"bar"}');
    expect($curlOpts[CURLOPT_HTTPHEADER])->toContain('Content-Type: application/json');
});

test('it normalizes a form body', function () {
    $handler = new FetchHandler();
    $options = ['form' => ['foo' => 'bar', 'baz' => 'qux']];
    $curlOpts = $handler->normalizeFetchOptions('https://example.com', $options);

    expect($curlOpts[CURLOPT_POSTFIELDS])->toBe('foo=bar&baz=qux');
    expect($curlOpts[CURLOPT_HTTPHEADER])->toContain('Content-Type: application/x-www-form-urlencoded');
});

test('it normalizes bearer token authentication', function () {
    $handler = new FetchHandler();
    $options = ['auth' => ['bearer' => 'my-token']];
    $curlOpts = $handler->normalizeFetchOptions('https://example.com', $options);

    expect($curlOpts[CURLOPT_HTTPHEADER])->toContain('Authorization: Bearer my-token');
});

test('it normalizes proxy settings from a ProxyConfig object', function () {
    $handler = new FetchHandler();
    $proxy = new ProxyConfig('proxy.host', 8080);
    $options = ['proxy' => $proxy];
    $curlOpts = $handler->normalizeFetchOptions('https://example.com', $options);

    expect($curlOpts[CURLOPT_PROXY])->toBe('proxy.host:8080');
});

test('it ignores special fetch options like retry and cache', function () {
    $handler = new FetchHandler();
    $options = ['retry' => true, 'cache' => true, 'stream' => true];
    $curlOpts = $handler->normalizeFetchOptions('https://example.com', $options);

    expect($curlOpts)->not->toHaveKeys(['retry', 'cache', 'stream']);
});
