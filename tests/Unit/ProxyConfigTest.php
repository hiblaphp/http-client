<?php

use Hibla\HttpClient\ProxyConfig;

describe('ProxyConfig Value Object', function () {

    it('constructs an HTTP proxy correctly', function () {
        $proxy = new ProxyConfig('proxy.example.com', 8080, 'user', 'pass', 'http');

        expect($proxy->host)->toBe('proxy.example.com');
        expect($proxy->port)->toBe(8080);
        expect($proxy->username)->toBe('user');
        expect($proxy->password)->toBe('pass');
        expect($proxy->type)->toBe('http');
    });

    it('uses the correct cURL constant for an HTTP proxy', function () {
        $proxy = ProxyConfig::http('proxy.example.com', 8080);
        expect($proxy->getCurlProxyType())->toBe(CURLPROXY_HTTP);
    });

    it('uses the correct cURL constant for a SOCKS4 proxy', function () {
        $proxy = ProxyConfig::socks4('proxy.example.com', 1080);
        expect($proxy->getCurlProxyType())->toBe(CURLPROXY_SOCKS4);
    });

    it('uses the correct cURL constant for a SOCKS5 proxy', function () {
        $proxy = ProxyConfig::socks5('proxy.example.com', 1080);
        expect($proxy->getCurlProxyType())->toBe(CURLPROXY_SOCKS5);
    });

    it('generates the correct proxy URL string with authentication', function () {
        $proxy = new ProxyConfig('proxy.example.com', 8080, 'user', 'pass', 'http');
        expect($proxy->getProxyUrl())->toBe('http://user:pass@proxy.example.com:8080');
    });

    it('generates the correct proxy URL string without authentication', function () {
        $proxy = ProxyConfig::http('proxy.example.com', 8080);
        expect($proxy->getProxyUrl())->toBe('http://proxy.example.com:8080');
    });
    
    it('handles a null password correctly in the URL string', function () {
        $proxy = new ProxyConfig('proxy.example.com', 8080, 'user', null, 'http');
        expect($proxy->getProxyUrl())->toBe('http://user@proxy.example.com:8080');
    });
    
});