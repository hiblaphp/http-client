<?php

namespace Hibla\Http\Handlers;

use Hibla\Http\Request;
use Hibla\Http\Response;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Helper class for configuring HTTP request retry logic and options.
 *
 * Provides a centralized way to apply various HTTP request configurations
 * including headers, authentication, body data, and other cURL options
 * before sending the request.
 */
final class RetryHelperHandler
{
    /**
     * Configures an HTTP request object with the provided options and sends it.
     *
     * Applies various request configurations like headers, authentication, body data,
     * timeouts, and SSL verification settings. Supports multiple authentication methods
     * including Bearer tokens and Basic auth.
     *
     * @param  Request  $request  The HTTP request object
     * @param  string  $url  The target URL for the request
     * @param array{
     *     headers?: array<string, string>,
     *     method?: string,
     *     body?: string,
     *     json?: array<string, mixed>,
     *     form?: array<string, mixed>,
     *     timeout?: int,
     *     user_agent?: string,
     *     verify_ssl?: bool,
     *     auth?: array{
     *         bearer?: string,
     *         basic?: array{username: string, password: string}
     *     }
     * } $options Configuration options for the request
     * @return PromiseInterface<Response> The result of the request send operation
     */
    public static function getRetryLogic(Request $request, string $url, array $options = []): PromiseInterface
    {
        if (isset($options['headers']) && is_array($options['headers'])) {
            $request->headers($options['headers']);
        }

        if (isset($options['method']) && is_string($options['method'])) {
            $method = strtoupper($options['method']);
        } else {
            $method = 'GET';
        }

        if (isset($options['body']) && is_string($options['body'])) {
            $request->body($options['body']);
        }

        if (isset($options['json']) && is_array($options['json'])) {
            $request->json($options['json']);
        }

        if (isset($options['form']) && is_array($options['form'])) {
            $request->form($options['form']);
        }

        if (isset($options['timeout']) && is_int($options['timeout'])) {
            $request->timeout($options['timeout']);
        }

        if (isset($options['user_agent']) && is_string($options['user_agent'])) {
            $request->userAgent($options['user_agent']);
        }

        if (isset($options['verify_ssl']) && is_bool($options['verify_ssl'])) {
            $request->verifySSL($options['verify_ssl']);
        }

        if (isset($options['auth']) && is_array($options['auth'])) {
            if (isset($options['auth']['bearer']) && is_string($options['auth']['bearer'])) {
                $request->bearerToken($options['auth']['bearer']);
            } elseif (isset($options['auth']['basic']) && is_array($options['auth']['basic'])) {
                $basicAuth = $options['auth']['basic'];
                if (isset($basicAuth['username'], $basicAuth['password'])
                    && is_string($basicAuth['username'])
                    && is_string($basicAuth['password'])) {
                    $request->basicAuth($basicAuth['username'], $basicAuth['password']);
                }
            }
        }

        return $request->send($method, $url);
    }
}
