# Hibla HTTP Client

**A fluent, immutable, async-first HTTP client for PHP built on the Hibla Event Loop.**

A high-performance Psr7 Async Compatible HTTP client with a clean chainable API, first-class streaming, Server-Sent Events, file upload/download, cookie management, retry logic, proxy support, and a full interceptor pipeline. Everything is built on top of [Hibla Promise](https://github.com/hiblaphp/promise) and [Hibla Event Loop](https://github.com/hiblaphp/event-loop).

> **Transport:** This library currently uses cURL (`ext-curl`) as its HTTP transport layer. Alternative transports, including a native socket-based transport via `hiblaphp/socket`, are planned for a future release.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/http-client.svg?style=flat-square)](https://github.com/hiblaphp/http-client/releases)
[![Tests](https://github.com/hiblaphp/http-client/actions/workflows/test.yml/badge.svg)](https://github.com/hiblaphp/http-client/actions/workflows/test.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/hiblaphp/http-client.svg?style=flat-square)](https://packagist.org/packages/hiblaphp/http-client)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

**Getting started**
- [Installation](#installation)
- [Quick start](#quick-start)
- [How it works](#how-it-works)

**Entry points**
- [The `Http` facade](#the-http-facade)
- [Static Shorthand vs. Explicit Intent](#static-shorthand-vs-explicit-client)
- [Direct usage and dependency injection](#direct-usage-and-dependency-injection)

**Making requests**
- [The fluent builder](#the-fluent-builder)
- [HTTP methods](#http-methods)
  - [Query parameters](#query-parameters)
- [The `fetch()` API](#the-fetch-api)
- [Headers](#headers)
- [Header validation](#header-validation)
- [Authentication](#authentication)
- [HTTP Version & Negotiation](#http-version--negotiation)
  - [Silent Fallback logic](#silent-fallback-logic)
  - [Inspecting the Negotiated Version](#inspecting-the-negotiated-version)
- [Request body](#request-body)
  - [JSON](#json)
  - [Form data](#form-data)
  - [Multipart and file attachments](#multipart-and-file-attachments)
  - [Raw body](#raw-body)
  - [XML](#xml)

**Working with responses**
- [Response inspection](#response-inspection)
- [JSON responses](#json-responses)
- [XML responses](#xml-responses)
- [Status checks](#status-checks)
- [HTTP version](#http-version)
- [4xx and 5xx responses](#4xx-and-5xx-responses)

**Transport configuration**
- [Timeouts](#timeouts)
- [Redirects](#redirects)
- [SSL verification](#ssl-verification)
- [HTTP version negotiation](#http-version-negotiation)
- [Proxy](#proxy)
- [Raw cURL options](#raw-curl-options)

**Retry**
- [Basic retry](#basic-retry)
- [Full retry configuration](#full-retry-configuration)

**Cancellation**
- [Cancelling requests](#cancellation)
- [Partial file cleanup](#partial-file-cleanup)
- [External cancellation with `CancellationToken`](#external-cancellation-with-cancellationtoken)

**Cookies**
- [One-shot cookies](#one-shot-cookies)
- [Session cookie jar](#session-cookie-jar)
- [Automatic Eviction (Side Effects)](#automatic-eviction-side-effects)
- [Sharing a jar across requests](#sharing-a-jar-across-requests)
- [Cookies with attributes](#cookies-with-attributes)
- [Clearing cookies](#clearing-cookies)
- [Custom cookie jar implementations](#custom-cookie-jar-implementations)

**Interceptors**
- [Overview](#overview)
- [`interceptRequest()`](#interceptrequest)
- [`interceptResponse()`](#interceptresponse)
- [`intercept()` — full pipeline control](#intercept--full-pipeline-control)
- [Interceptor ordering](#interceptor-ordering)
- [Shared interceptor stacks](#shared-interceptor-stacks)
- [Throwing on 4xx and 5xx with an interceptor](#throwing-on-4xx-and-5xx-with-an-interceptor)

**Streaming**
- [Streaming responses](#streaming-responses)
- [Pull model: async incremental reads](#pull-model-async-incremental-reads)
- [Push model: chunk callback](#push-model-chunk-callback)
- [Combining push and pull](#combining-push-and-pull)
- [File download](#file-download)
- [File upload](#file-upload)

**Server-Sent Events**
- [SSE basic usage](#sse-basic-usage)
- [SSE data formats](#sse-data-formats)
- [Transforming events with `map()`](#transforming-events-with-map)
- [SSE reconnection](#sse-reconnection)
- [Cancelling an SSE connection](#cancelling-an-sse-connection)

**Additional features**
- [URI template parameters](#uri-template-parameters)
- [User agent](#user-agent)

**Advanced usage**
- [Concurrent execution (all)](#concurrent-execution-all)
- [Sliding window concurrency (concurrent)](#sliding-window-concurrency-concurrent)
- [Block-based execution (batch)](#block-based-execution-batch)
- [Resilient execution (settled variants)](#resilient-execution-settled-variants)
- [High-performance mapping (map)](#high-performance-mapping-map)
- [Competitive requests (any/race)](#competitive-requests-anyrace)
- [Side effects (forEach)](#side-effects-foreach)
- [Sequential reduction (reduce)](#sequential-reduction-reduce)
- [Custom transport handlers (withHandler)](#custom-transport-handlers)
- [Custom transport options (withTransportOptionsBuilder)](#custom-transport-options)

**Testing**
- [Testing](#testing)

**Reference**
- [API Reference](#api-reference)
  - [`Http` facade](#http-facade)
  - [Builder methods](#builder-methods)
  - [`RequestInterface`](#requestinterface)     
  - [`SSEBuilderInterface`](#ssebuilderinterface)
  - [`ResponseInterface`](#responseinterface)
  - [`CookieJarInterface`](#cookiejarinterface)
- [Exceptions](#exceptions)

**Meta**
- [Development](#development)
- [Credits](#credits)
- [License](#license)
---

## Installation

```bash
composer require hiblaphp/http-client
```

**Requirements:**
- PHP 8.3+
- `ext-curl`
- `hiblaphp/event-loop`
- `hiblaphp/promise`
- `hiblaphp/stream`
- `hiblaphp/async`

---

## Quick start

```php
use Hibla\HttpClient\Http;
use function Hibla\await;

// GET
$response = await(Http::get('https://api.example.com/users'));
echo $response->body();

// POST with JSON
$response = await(Http::post('https://api.example.com/users', [
    'name'  => 'Reymart Calicdan',
    'email' => 'reymartcalicdan@example.com',
]));
echo $response->status(); // 201

// Fluent builder
$response = await(
    Http::client()
        ->withToken($token)
        ->timeout(15)
        ->get('https://api.example.com/users')
);

// fetch()-style
$response = await(fetch('https://api.example.com/users', [
    'method' => 'POST',
    'json'   => ['name' => 'Alice'],
]));

// Promise chaining with then()
Http::get('https://api.example.com/users')
    ->then(function ($response) {
        echo $response->body();
        return Http::get('https://api.example.com/orders');
    })
    ->then(function ($response) {
        echo $response->body();
    })
    ->catch(function (\Throwable $e) {
        echo "Error: " . $e->getMessage();
    });
```

> **`await()` vs `.then()` chaining:** Both are fully supported. `await()` suspends
> the current fiber and gives you a flat, linear coding style and it is the recommended
> approach for most code. `.then()` chaining is useful when you want to compose
> request pipelines without entering a fiber, or when integrating with code that
> already works with raw promises. See [Hibla Promise](https://github.com/hiblaphp/promise)
> for the full promise API.

---

## How it works

Every method on the `Http` facade delegates to a fresh `HttpClient` instance. `HttpClient` is an **immutable fluent builder**: every configuration method returns a new clone, so chains can branch freely without side effects.

When a terminal method (`get()`, `post()`, `send()`, `stream()`, `download()`, `upload()`, `sse()`) is called, the request flows through a configurable **interceptor pipeline** running inside a dedicated fiber, before being dispatched by the underlying cURL handler. The handler returns a `PromiseInterface` that resolves to a `Response` once the transfer completes.

Because every request is a `PromiseInterface`, all requests are non-blocking. Multiple requests run concurrently under the same event loop without threads or forking:

```php
use function Hibla\await;
use Hibla\Promise\Promise;

// Three requests run in parallel
[$users, $orders, $stats] = await(Promise::all([
    Http::get('https://api.example.com/users'),
    Http::get('https://api.example.com/orders'),
    Http::get('https://api.example.com/stats'),
]));
```

> **New to promises and fibers?** The concurrency model used throughout this library — `await()`, `Promise::all()`, `async()`, and fiber-based suspension — is provided by two companion packages. See [Hibla Promise](https://github.com/hiblaphp/promise) for the promise API and [Hibla Async](https://github.com/hiblaphp/async) for the fiber primitives. Both packages include their own documentation and examples if you want to understand how non-blocking execution works under the hood.

---

## Entry points

### The `Http` facade

`Http` is a static facade that provides a convenient entry point for all HTTP operations. Every static call delegates to a fresh `HttpClient` instance behind the scenes, so there is no shared mutable state:

```php
use Hibla\HttpClient\Http;

$response = await(Http::get('https://api.example.com/users'));
$response = await(Http::post('https://api.example.com/users', $data));

$client = Http::client()
    ->withToken($token)
    ->timeout(30);
```

The facade is the most concise entry point and is appropriate for most applications. For cases where you need explicit dependency injection or container integration, see the next section.

### Static Shorthand vs. Explicit Client

The `Http` facade uses PHP's `__callStatic` magic method to provide a high-level shorthand for all client methods. This allows you to skip the `client()` call for one-off requests:

```php
// Static Shorthand
$response = await(Http::withToken($token)->get($url));

// Equivalent to:
$response = await(Http::client()->withToken($token)->get($url));
```

#### When to use the Static Facade
The static shorthand is perfect for **one-off requests** or simple scripts where conciseness is the priority. It feels natural and requires the least amount of boilerplate.

#### Trade-offs & Considerations
While convenient, the static facade has a few trade-offs to keep in mind:

1.  **Performance Overhead:** Every time you start a chain with a static method (like `Http::withToken()`), the library must instantiate a brand new `HttpClient` object. In high-frequency loops (e.g., thousands of requests), this repeated instantiation and magic-method resolution adds unnecessary CPU overhead.
2.  **Lack of Reusability:** Because the facade returns a fresh instance every time you "start" a call, you cannot easily share a base configuration across your application without explicitly saving it to a variable.
3.  **Dependency Injection:** Static calls are harder to swap out in unit tests compared to an injected `HttpClientInterface`. While the Hibla Testing Plugin handles static mocking perfectly, explicit injection is often preferred in large-scale enterprise architectures.

### Direct usage and dependency injection

The `Http` facade is a convenience wrapper. You can bypass it entirely and work with `HttpClient` or `HttpClientInterface` directly. This is useful when wiring up a pre-configured client in a dependency injection container, when writing code that should be testable without a facade, or when you want explicit control over which handler instance is used.

`HttpClient` is the concrete implementation. `HttpClientInterface` is the full contract and is the recommended type hint for constructor injection:

```php
use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\Interfaces\HttpClientInterface;

$client = new HttpClient();

$response = await(
    $client
        ->withToken($token)
        ->timeout(30)
        ->get('https://api.example.com/users')
);
```

Because `HttpClient` is immutable, a single pre-configured instance is safe to share across the entire application. Every method call returns a new clone and the base instance is never mutated:

```php
$container->singleton(HttpClientInterface::class, function () {
    return (new HttpClient())
        ->withToken(config('api.token'))
        ->withUserAgent('MyApp/1.0')
        ->timeout(30)
        ->intercept($loggingMiddleware)
        ->intercept($metricsMiddleware);
});
```

Then inject it wherever it is needed:

```php
class UserRepository
{
    public function __construct(private readonly HttpClientInterface $http) {}

    public function find(int $id): PromiseInterface
    {
        return $this->http->get("https://api.example.com/users/{$id}");
    }

    public function create(array $data): PromiseInterface
    {
        return $this->http->post('https://api.example.com/users', $data);
    }
}
```

Each call to `find()` or `create()` derives a new clone from the shared base. The base instance's token, user agent, timeout, and interceptors are inherited by every derived request, and none of them can mutate the base.

---

## Making requests

### The fluent builder

`Http::client()` (or `new HttpClient()`) returns a fresh builder. Every method returns a new clone, so a shared base configuration can safely derive multiple independent requests:

```php
$client = Http::client()
    ->withToken($token)
    ->withUserAgent('MyApp/1.0')
    ->timeout(30)
    ->verifySSL(true);

// Each call returns a new clone — $client is never mutated
$users  = await($client->get('https://api.example.com/users'));
$orders = await($client->get('https://api.example.com/orders'));
```

### HTTP methods

```php
$response = await(Http::get('https://api.example.com/users'));
$response = await(Http::post('https://api.example.com/users', $data));
$response = await(Http::put('https://api.example.com/users/1', $data));
$response = await(Http::patch('https://api.example.com/users/1', $data));
$response = await(Http::delete('https://api.example.com/users/1'));
$response = await(Http::head('https://api.example.com/users'));
$response = await(Http::options('https://api.example.com/users'));

// Arbitrary method
$response = await(Http::client()->send('PURGE', 'https://cdn.example.com/cache'));
```

#### Query parameters

Pass a flat array as the second argument to `get()` to append query parameters:

```php
$response = await(Http::get('https://api.example.com/users', [
    'page'    => 1,
    'perPage' => 25,
    'sort'    => 'name',
]));
// Requests: GET /users?page=1&perPage=25&sort=name
```

### The `fetch()` API

`Http::fetch()` and `fetch()` offers a JavaScript-like interface for callers who prefer a flat options array over a fluent chain:

>Important!: The fetch() API is limited to standard Request/Response cycles.
This interface is designed for simplicity and does not support specialized transport modes. If you need to use Streaming, File Downloads, File Uploads, or Server-Sent Events (SSE), you must use the fluent builder API (Http::client()->...) which provides the necessary configuration methods and return types for those features.

```php
$response = await(fetch('https://api.example.com/users', [
    'method'           => 'POST',
    'headers'          => ['X-Request-Id' => 'abc123'],
    'json'             => ['name' => 'Alice'],
    'timeout'          => 15,
    'connect_timeout'  => 5,
    'verify_ssl'       => true,
    'follow_redirects' => true,
    'max_redirects'    => 5,
    'retry'            => true,
    'auth'             => ['bearer' => $token],
]));
```

All options are translated to the same fluent builder calls internally, so every feature is available through both APIs. Integer keys are forwarded as raw cURL options.

Supported options:

| Option              | Type                                | Description                                        |
| ------------------- | ----------------------------------- | -------------------------------------------------- |
| `method`            | `string`                            | HTTP method (default: `GET`)                       |
| `headers`           | `array<string, string\|string[]>`   | Request headers                                    |
| `json`              | `array`                             | JSON-encode and set as body                        |
| `form`              | `array`                             | URL-encode and set as body                         |
| `multipart`         | `array`                             | Multipart form data                                |
| `body`              | `string`                            | Raw request body                                   |
| `auth`              | `array`                             | `bearer`, `basic`, or `digest` credentials         |
| `timeout`           | `int`                               | Total request timeout in seconds                   |
| `connect_timeout`   | `int`                               | Connection timeout in seconds                      |
| `follow_redirects`  | `bool`                              | Whether to follow redirects                        |
| `max_redirects`     | `int`                               | Maximum number of redirects                        |
| `verify_ssl`        | `bool`                              | Whether to verify SSL certificates                 |
| `user_agent`        | `string`                            | Custom User-Agent string                           |
| `http_version`      | `string`                            | Protocol version (`1.1`, `2`, `2.0`, `3`, `3.0`)  |
| `retry`             | `bool\|array\|RetryConfig`          | Retry configuration                                |
| `proxy`             | `string\|array\|ProxyConfig`        | Proxy configuration                                |
| `cookies`           | `array<string, string>`             | One-shot cookies for this request                  |
| `cookie_jar`        | `CookieJarInterface`                | Cookie jar instance for session management         |
| `intercept`         | `callable\|callable[]`              | Full pipeline interceptor(s)                       |
| `interceptRequest`  | `callable\|callable[]`              | Request interceptor(s)                             |
| `interceptResponse` | `callable\|callable[]`              | Response interceptor(s)                            |
| `<int>`             | `mixed`                             | Raw cURL option (integer key = `CURLOPT_*`)        |

### Headers

```php
// Single header
Http::client()->withHeader('X-Request-Id', 'abc123');

// Multiple headers at once
Http::client()->withHeaders([
    'X-Request-Id' => 'abc123',
    'X-Tenant-Id'  => 'tenant-42',
]);

// Convenience shortcuts
Http::client()->contentType('application/json');
Http::client()->accept('application/json');
Http::client()->asJson();   // Content-Type: application/json
Http::client()->asForm();   // Content-Type: application/x-www-form-urlencoded
Http::client()->asXml();    // Content-Type: application/xml

// User-Agent
Http::client()->withUserAgent('MyApp/1.0');

// Remove a header
Http::client()->withoutHeader('X-Unwanted');
```

#### Header Validation

All header names and values are validated against [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110#section-5) before a request is sent. An `InvalidArgumentException` is thrown immediately on any violation, so injection attempts are caught at call time rather than silently forwarded.

**Header names** must be valid RFC 9110 tokens — one or more `tchar` characters (`A–Z`, `a–z`, `0–9`, and `!#$%&'*+-.^_`|~`). Spaces, colons, control characters, and non-ASCII bytes are all rejected.

```php
Http::client()->withHeader('Bad Header', 'value');      // throws — space in name
Http::client()->withHeader("X-Foo\r\nX-Bar", 'value'); // throws — CRLF injection
Http::client()->withHeader('X-Héader', 'value');        // throws — non-ASCII byte
```

**Header values** must conform to RFC 9110 §5.5. The following are enforced:

- CR (`\r`), LF (`\n`), and NUL (`\0`) are unconditionally rejected — these are the primary vectors for HTTP response-splitting and header injection attacks.
- All other control characters except HTAB (`\t`) are rejected.
- DEL (0x7F) is rejected.
- Leading or trailing whitespace (SP or HTAB) is rejected.
- `obs-text` bytes (0x80–0xFF) are permitted for legacy interoperability.

```php
Http::client()->withHeader('X-Id', "abc\r\nX-Evil: injected"); // throws — CRLF injection
Http::client()->withHeader('X-Id', " abc");                    // throws — leading space
Http::client()->withHeader('X-Id', "abc\x00");                 // throws — NUL byte
Http::client()->withHeader('X-Id', "abc");                     // ok
Http::client()->withHeader('X-Id', '');                        // ok — empty value is valid per RFC 9110
```

### Authentication

The three auth strategies are mutually exclusive. Setting one removes any previously configured auth:

```php
// Bearer token
Http::client()->withToken($token);
Http::client()->withToken($token, 'Bearer'); // explicit type
Http::client()->withToken('Bearer ' . $token); // prefix is stripped automatically

// HTTP Basic
Http::client()->withBasicAuth('username', 'password');

// HTTP Digest
Http::client()->withDigestAuth('username', 'password');
```

Here is the updated documentation for the HTTP versioning and fallback behavior.

---

### HTTP Version & Negotiation

Hibla defaults to **HTTP/2.0** (via TLS) for all requests when using the default cURL transport. However, the requested version is treated as a **negotiation target**, not a hard requirement.

```php
// Request specific versions
Http::client()->http1()->get($url); // Force HTTP/1.1
Http::client()->http2()->get($url); // Target HTTP/2 (Default)
Http::client()->http3()->get($url); // Target HTTP/3 (QUIC)
```

#### Silent Fallback logic

To ensure maximum compatibility across different server environments and `ext-curl` versions, the client implements a silent fallback strategy:

*   **HTTP/2 Fallback:** If you request HTTP/2 but the server only supports older protocols, cURL will automatically and silently negotiate the connection down to HTTP/1.1.
*   **HTTP/3 Fallback:** If you request HTTP/3 but the server does not support it, or if your local cURL installation was not compiled with a QUIC library (like `ngtcp2` or `quiche`), the client will silently fall back to HTTP/1.1 to prevent the request from failing.

#### Inspecting the Negotiated Version

Because of the fallback logic, the version you *requested* may not be the version the server actually *used*. You can inspect the final result on the response object:

```php
$response = await(Http::client()->http3()->get($url));

// Returns the canonical version string: '1.1', '2', or '3'
// Returns null if the version could not be determined (e.g. in some mock scenarios)
$version = $response->getHttpVersion(); 

// Returns the full protocol string: 'HTTP/1.1', 'HTTP/2', or 'HTTP/3'
$fullString = $response->getHttpVersionString();

if ($response->getHttpVersion() !== '3') {
    // Connection fell back to a lower protocol
}
```


> Note: HTTP/3 support in PHP requires a very recent version of `ext-curl` and a cURL binary compiled with HTTP/3 support. If these requirements are not met, the client ensures your application remains functional by utilizing the HTTP/1.1 fallback path automatically.

### Request body

The four body strategies (JSON, form, multipart, raw) are mutually exclusive. Each method sets the body and adjusts `Content-Type` accordingly.

#### JSON

```php
$response = await(
    Http::client()
        ->withJson(['name' => 'Alice', 'role' => 'admin'])
        ->post('https://api.example.com/users')
);

// post(), put(), and patch() auto-encode arrays as JSON when no body is set
$response = await(Http::post('https://api.example.com/users', ['name' => 'Alice']));
```

#### Form data

```php
$response = await(
    Http::client()
        ->withForm(['username' => 'alice', 'password' => 'secret'])
        ->post('https://auth.example.com/login')
);
```

#### Multipart and file attachments

```php
// Multipart fields only
$response = await(
    Http::client()
        ->withMultipart(['name' => 'Alice', 'role' => 'admin'])
        ->post('https://api.example.com/users')
);

// Attach a file by path
$response = await(
    Http::client()
        ->withFile('avatar', '/path/to/avatar.jpg')
        ->post('https://api.example.com/users/1/avatar')
);

// Explicit filename and MIME type
$response = await(
    Http::client()
        ->withFile('document', '/path/to/file.pdf', 'report.pdf', 'application/pdf')
        ->post('https://api.example.com/documents')
);

// PSR-7 stream or UploadedFileInterface
$response = await(
    Http::client()
        ->withFile('avatar', $uploadedFile)
        ->post('https://api.example.com/users/1/avatar')
);

// Fields and files together
$response = await(
    Http::client()
        ->multipartWithFiles(
            data:  ['name' => 'Alice', 'department' => 'Engineering'],
            files: ['avatar' => '/path/to/avatar.jpg']
        )
        ->post('https://api.example.com/users')
);
```

`withFile()` accepts: an absolute file path string, a PHP resource, a PSR-7 `StreamInterface`, or a PSR-7 `UploadedFileInterface`.

#### Raw body

```php
$response = await(
    Http::client()
        ->body('{"custom":"payload"}')
        ->contentType('application/json')
        ->post('https://api.example.com/data')
);
```

#### XML

```php
// From a string
$response = await(
    Http::client()
        ->withXml('<user><name>Alice</name></user>')
        ->post('https://api.example.com/users')
);

// From a SimpleXMLElement
$xml = new SimpleXMLElement('<user/>');
$xml->addChild('name', 'Alice');

$response = await(
    Http::client()
        ->withXml($xml)
        ->post('https://api.example.com/users')
);
```

---

## Working with responses

### Response inspection

```php
$response = await(Http::get('https://api.example.com/users'));

$body    = $response->body();             // string
$data    = $response->json();             // decoded array or scalar
$xml     = $response->xml();             // SimpleXMLElement|null
$status  = $response->status();          // int
$phrase  = $response->getReasonPhrase(); // string
$all     = $response->headers();         // array<string, string> — lowercase keys
$type    = $response->header('content-type'); // string|null
```

### JSON responses

`json()` accepts an optional dot-notation key and a default value. A direct key match takes priority over dot-notation traversal, so keys containing literal dots are still reachable:

```php
$response = await(Http::get('https://api.example.com/users/1'));

$data = $response->json();                  // full decoded array
$name = $response->json('name');            // specific key
$city = $response->json('address.city');    // nested path
$role = $response->json('role', 'viewer');  // with default fallback
```

### XML responses

```php
$response = await(Http::get('https://api.example.com/users/1.xml'));
$xml = $response->xml(); // SimpleXMLElement|null

if ($xml !== null) {
    echo (string) $xml->name;
}
```

### Status checks

```php
$response->successful();  // true for 2xx
$response->failed();      // true for 4xx or 5xx
$response->clientError(); // true for 4xx
$response->serverError(); // true for 5xx
```

### HTTP version

```php
$response->getHttpVersion();       // '1.1', '2', '3', or null
$response->getHttpVersionString(); // 'HTTP/2', 'HTTP/1.1', etc.
```

### 4xx and 5xx responses

**Hibla HTTP Client does not throw exceptions on 4xx or 5xx responses.** A completed HTTP exchange resolves the promise with a `Response` object regardless of status code. Only transport-level failures (connection refused, DNS error, SSL failure, timeout) reject the promise.

```php
$response = await(Http::get('https://api.example.com/users/999'));

if ($response->clientError()) {
    echo "Not found: " . $response->status();
}

if ($response->serverError()) {
    echo "Server error: " . $response->body();
}
```

If your application prefers exception-based error handling for HTTP errors, register a response interceptor to throw on non-2xx responses. See [Throwing on 4xx and 5xx with an interceptor](#throwing-on-4xx-and-5xx-with-an-interceptor).

---

## Transport configuration

### Timeouts

```php
Http::client()
    ->timeout(30)         // total request timeout in seconds
    ->connectTimeout(5);  // TCP + SSL handshake only
```

Setting `timeout(0)` disables the operation timeout entirely. The `connectTimeout` always applies regardless.

> **Note:** The operation timeout is disabled by default for `stream()`, `download()`, `upload()`, and `sse()` because these operations are inherently long-lived and an arbitrary timeout would interrupt valid transfers. The connection timeout still applies to all of them. If you need an operation timeout on a stream or upload, call `timeout()` explicitly.

### Redirects

```php
Http::client()->redirects(follow: true, max: 10);

// Disable redirects entirely
Http::client()->redirects(false);
```

### SSL verification

```php
Http::client()->verifySSL(true);  // default
Http::client()->verifySSL(false); // disable — only for controlled test environments
```

### HTTP version negotiation

```php
Http::client()->http1();               // HTTP/1.1 specifically
Http::client()->http2();               // HTTP/2 with fallback to HTTP/1.1
Http::client()->http3();               // HTTP/3 with fallback to HTTP/1.1
Http::client()->httpVersion('2.0');    // by version string
```

### Proxy

```php
// HTTP proxy
Http::client()->withProxy('proxy.example.com', 8080);
Http::client()->withProxy('proxy.example.com', 8080, 'user', 'pass');

// SOCKS4
Http::client()->withSocks4Proxy('proxy.example.com', 1080);

// SOCKS5
Http::client()->withSocks5Proxy('proxy.example.com', 1080, 'user', 'pass');

// From a ProxyConfig value object
use Hibla\HttpClient\ValueObjects\ProxyConfig;

Http::client()->withProxyConfig(ProxyConfig::socks5('proxy.example.com', 1080));

// Bypass proxy for a specific request
$client->withoutProxy()->get('https://internal.example.com/health');
```

### Raw cURL options

Use raw cURL options as an escape hatch for settings not covered by the fluent API. Integer keys are `CURLOPT_*` constants:

```php
Http::client()
    ->withCurlOption(CURLOPT_INTERFACE, 'eth0')
    ->withCurlOption(CURLOPT_CAINFO, '/path/to/custom-ca.pem')
    ->get('https://api.example.com');

Http::client()
    ->withCurlOptions([
        CURLOPT_INTERFACE => 'eth0',
        CURLOPT_CAINFO    => '/path/to/custom-ca.pem',
    ])
    ->get('https://api.example.com');
```

> Raw cURL options bypass all validation and may conflict with options set by the transport layer internally. Prefer the typed fluent methods wherever possible.

---

## Retry

Hibla HTTP Client has built-in retry support with exponential backoff. Retries are applied at the transport level after all interceptors have run. Only transient failures trigger a retry — 4xx client errors (except 429 Too Many Requests) are never retried automatically.

### Basic retry

```php
// Enable with defaults: 3 retries, 1 s base delay, 2× backoff
Http::client()->retry();

// Custom parameters
Http::client()->retry(maxRetries: 5, baseDelay: 0.5, backoffMultiplier: 1.5);

// Disable retries — useful to opt out of a globally configured policy
Http::client()->withoutRetries();
```

By default the following HTTP status codes trigger a retry: 408, 429, 500, 502, 503, 504. Retries also fire on transport-level errors (connection timeouts, DNS failures, etc.).

### Full retry configuration

For fine-grained control use `RetryConfig` directly:

```php
use Hibla\HttpClient\ValueObjects\RetryConfig;

Http::client()->withRetryConfig(new RetryConfig(
    maxRetries:           3,
    baseDelay:            1.0,
    maxDelay:             30.0,
    backoffMultiplier:    2.0,
    jitter:               true,
    retryableStatusCodes: [429, 500, 502, 503, 504],
    retryableExceptions:  ['timeout', 'Could not resolve host'],
));
```

Retries use exponential backoff. When `jitter` is `true`, a small random offset is added to each delay to prevent multiple clients from hammering the server in lockstep after a shared failure.

---

## Cancellation

Every promise returned by this library supports cancellation. Calling `cancel()` on a promise immediately aborts the underlying http request, freeing the connection without waiting for a response:

```php
$promise = Http::get('https://api.example.com/users');

// Cancel at any point — the underlying HTTP request is aborted immediately
$promise->cancel();
```

Cancellation works for all request types including streaming, download, upload, and SSE:

```php
// Cancel a stream after it resolves — closes the connection and stops further data delivery
$streamPromise = Http::stream('https://api.example.com/large-export');
$response = await($streamPromise);
$streamPromise->cancel();

// Cancel a download after a timeout
$downloadPromise = Http::download('https://files.example.com/archive.zip', '/tmp/archive.zip');
Loop::addTimer(5.0, fn() => $downloadPromise->cancel());
```

For SSE connections, cancellation closes the connection and suppresses any further reconnection attempts, even if reconnection is configured:

```php
$ssePromise = Http::sse('https://api.example.com/events')
    ->reconnect(maxAttempts: 10)
    ->onEvent(fn($e, $ctrl) => handleEvent($e))
    ->connect();

// Cancels the connection and stops all reconnection attempts
$ssePromise->cancel();
```

### Partial file cleanup

When a `download()` or `upload()` is cancelled mid-transfer, the library cleans up automatically:

- **Downloads:** the partially written destination file is deleted immediately. The destination path will not exist after cancellation.
- **Uploads:** any temporary files created for multipart uploads are deleted immediately.

This means you never need to check for or clean up leftover partial files after cancellation:

```php
$promise = Http::download('https://files.example.com/archive.zip', '/tmp/archive.zip');

Loop::addTimer(2.0, fn() => $promise->cancel());

try {
    await($promise);
} catch (CancelledException $e) {
    // '/tmp/archive.zip' has already been deleted — no cleanup needed
}
```

### External cancellation with `CancellationToken`

Promise cancellation works well for individual chains, but when you need one signal to cancel multiple independent operations with user-initiated abort, a timeout ceiling, a shutdown hook you can also use `hiblaphp/cancellation` built in to `hiblaphp/async` alongside this library.

```bash
composer require hiblaphp/cancellation
```

The `CancellationTokenSource` owns the cancel signal. Pass the read-only `$token` into operations and track the promises they return. Calling `cancel()` on the source cancels every tracked promise in one call:

```php
use Hibla\Cancellation\CancellationTokenSource;

$cts = new CancellationTokenSource();

$usersPromise  = Http::get('https://api.example.com/users');
$ordersPromise = Http::get('https://api.example.com/orders');

$cts->token->track($usersPromise);
$cts->token->track($ordersPromise);

// One call aborts both HTTP requests
$cts->cancel();
```

#### Timeout

Pass a timeout in seconds to the constructor and the source cancels automatically when it elapses:

```php
// Both requests share a hard 10-second ceiling
$cts = new CancellationTokenSource(10.0);

$cts->token->track(Http::get('https://api.example.com/users'));
$cts->token->track(Http::get('https://api.example.com/orders'));
```

#### Combining user abort and timeout

`createLinkedTokenSource()` merges multiple signals into one token. The linked source cancels as soon as any parent token cancels:

```php
use Hibla\Cancellation\CancellationTokenSource;
use function Hibla\async;
use function Hibla\await;

$userCts    = new CancellationTokenSource();     // cancelled when user clicks abort
$timeoutCts = new CancellationTokenSource(30.0); // hard 30-second ceiling

$linkedCts = CancellationTokenSource::createLinkedTokenSource(
    $userCts->token,
    $timeoutCts->token
);

$workflow = async(function () use ($linkedCts) {
    try {
        $user   = await(Http::get('https://api.example.com/users/1'), $linkedCts->token);
        $orders = await(Http::get('https://api.example.com/orders'), $linkedCts->token);

        return compact('user', 'orders');
    } catch (\Hibla\Promise\Exceptions\CancelledException $e) {
        // Fired for either user abort or timeout — partial files already cleaned up
        return null;
    }
});

$abortButton->onClick(fn() => $userCts->cancel());

$result = await($workflow);
```

Passing a `CancellationToken` as the second argument to `await()` automatically calls `track()` for you, so you do not need to call it manually at every `await()` site.

#### Downloads and uploads with a token

Tracking a `download()` or `upload()` promise against a token works exactly like any other request. Partial file cleanup still fires automatically on cancellation:

```php
$cts = new CancellationTokenSource(60.0); // 60-second ceiling on the transfer

$promise = Http::download(
    'https://files.example.com/archive.zip',
    '/tmp/archive.zip'
);

$cts->token->track($promise);

try {
    $result = await($promise);
} catch (\Hibla\Promise\Exceptions\CancelledException $e) {
    // Timed out — '/tmp/archive.zip' has already been deleted
}
```

> **See also:** For the full `CancellationToken` API — linked sources, `onCancel()` cleanup registration, `throwIfCancelled()`, and `cancel()` vs `cancelChain()` — see the [hiblaphp/cancellation](https://github.com/hiblaphp/cancellation) documentation.

---

## Cookies

### One-shot cookies

Add cookies to a single request's `Cookie` header without any jar. These are not persisted and do not affect subsequent requests:

```php
Http::client()
    ->withCookie('session', 'abc123')
    ->withCookie('pref', 'dark-mode')
    ->get('https://api.example.com/dashboard');

Http::client()
    ->withCookies(['session' => 'abc123', 'pref' => 'dark-mode'])
    ->get('https://api.example.com/dashboard');
```

Cookie names must be valid RFC 2616 tokens and values must conform to the RFC 6265 cookie-octet character set. For arbitrary values, Base64-encode first:

```php
Http::client()->withCookie('data', base64_encode($arbitraryValue));
```

### Session cookie jar

The library ships with `CookieJar`, a fully RFC 6265 compliant in-memory cookie jar. It handles domain and path scoping, the `Secure` flag, `HttpOnly`, `SameSite`, expiry via `Max-Age` and `Expires`, host-only cookies (when no `Domain` attribute is present in `Set-Cookie`), and creation-time preservation on cookie replacement.

Enable it with `withCookieJar()`, which creates a fresh `CookieJar` instance scoped to that client. Cookies received in `Set-Cookie` response headers are stored and forwarded automatically on subsequent requests:

```php
$client = Http::client()->withCookieJar();

await($client->post('https://api.example.com/login', [
    'username' => 'alice',
    'password' => 'secret',
]));

// Session cookie from the login response is sent automatically
$response = await($client->get('https://api.example.com/profile'));
```
---

### Automatic Eviction (Side Effects)

Per RFC 6265, the client is responsible for evicting expired cookies. To ensure the server never receives stale data and to prevent unbounded memory growth in long-running processes (like workers), the CookieJar performs automatic cleanup.

>Reading the jar is a mutable operation.
Both getCookies() and getCookieHeader() (which are called automatically every time you send a request) execute clearExpired() as a side effect. This means that simply making a request can cause the underlying jar to mutate as it prunes expired cookies from its internal storage.

---

### Sharing a jar across requests

Because `CookieJar` is a mutable object, multiple client instances can share the same jar. Cookies set by one request are immediately visible to all others sharing that jar:

```php
use Hibla\HttpClient\CookieJar;

$jar = new CookieJar();
$client = Http::client()->useCookieJar($jar);

await($client->post('https://api.example.com/login', $credentials));

// Inspect the jar at any time
foreach ($jar->getAllCookies() as $cookie) {
    echo $cookie->getName() . '=' . $cookie->getValue() . "\n";
}

// Explicit expiry cleanup — recommended in long-running processes
$jar->clearExpired();
```

### Cookies with attributes

```php
Http::client()->cookieWithAttributes('session', 'abc123', [
    'domain'   => 'api.example.com',
    'path'     => '/v2',
    'secure'   => true,
    'httpOnly' => true,
    'maxAge'   => 3600,
    'sameSite' => 'Strict',
]);
```

If no jar is active when `cookieWithAttributes()` is called, an in-memory `CookieJar` is initialised automatically.

### Clearing cookies

`clearCookies()` is a **mutable operation** on the underlying jar. All client instances sharing that jar will see their cookies removed immediately:

```php
$client->clearCookies()->get('https://api.example.com/users');
```

### Custom cookie jar implementations

`CookieJarInterface` is the full contract for cookie storage. The built-in `CookieJar` is simply the default in-memory implementation. You can implement it to persist cookies across process restarts, store them in a database, or apply custom scoping logic:

```php
use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\ValueObjects\Cookie;

class RedisCookieJar implements CookieJarInterface
{
    public function __construct(private \Redis $redis) {}

    public function setCookie(Cookie $cookie): void
    {
        // Store cookie in Redis, keyed by domain and name
    }

    public function getCookies(string $domain, string $path, bool $isSecure = false): array
    {
        // Fetch from Redis and filter using $cookie->matches($domain, $path, $isSecure)
    }

    public function getAllCookies(): array
    {
        // Return all cookies regardless of scope
    }

    public function getCookieHeader(string $domain, string $path, bool $isSecure = false): string
    {
        // Build the Cookie header string for the given context
    }

    public function clearExpired(): void
    {
        // Redis TTL handles expiry automatically — no-op
    }

    public function clear(): void
    {
        // Delete all cookie keys from Redis
    }
}
```

Inject your implementation exactly like the built-in jar:

```php
$client = Http::client()->useCookieJar(new RedisCookieJar($redis));
```

The `Cookie` value object's `matches(string $domain, string $path, bool $isSecure)` method handles all RFC 6265 domain/path/secure matching logic, so your implementation does not need to reimplement it.

---

## Interceptors

### Overview

Interceptors let you modify requests and responses centrally before they are sent and after they are received. They form a pipeline that wraps every request dispatched from the client they are registered on.

>Interceptors are isolated from transport configuration.
Interceptors operate exclusively on the HTTP message layer (headers, body, method, URI, and cookies). Transport-level settings such as timeout(), connectTimeout(), retry(), proxy(), and raw withCurlOption()which are locked at the client level and cannot be accessed or modified within the interceptor pipeline. If you need different transport settings for different requests, you should fork the client using its immutable builder methods before dispatching.

**The interceptor pipeline runs inside a dedicated fiber.** This means `await()` is safe to call freely inside any interceptor. It suspends only the current fiber, not the event loop itself, so other in-flight requests continue running concurrently while an interceptor awaits async work. There is no additional fiber overhead per interceptor; all three interceptor tiers share a single fiber per request.

```php
$client = Http::client()->interceptRequest(function (RequestInterface $request): RequestInterface {
    $token = await(TokenCache::getOrRefresh()); // suspends this fiber only
    return $request->withToken($token);
});
```

### `interceptRequest()`

The simplest tier. Receives the outgoing `RequestInterface` and returns a (potentially modified) `RequestInterface`. The callback may return a plain `RequestInterface` or a `PromiseInterface` that resolves to one:

```php
// Synchronous transform
$client = Http::client()->interceptRequest(function (RequestInterface $request): RequestInterface {
    return $request->withHeader('X-Request-Id', uniqid());
});

// Async work — await() is safe because the pipeline runs in a fiber
$client = Http::client()->interceptRequest(function (RequestInterface $request): RequestInterface {
    $token = await(TokenCache::getOrRefresh());
    return $request->withToken($token);
});
```

### `interceptResponse()`

Receives the incoming `ResponseInterface` and returns a (potentially modified) `ResponseInterface`. Async work is fully supported:

```php
$client = Http::client()->interceptResponse(function (ResponseInterface $response): ResponseInterface {
    if ($response->status() === 401) {
        logger()->warning('Unauthorized response');
    }
    return $response;
});
```

### `intercept()` — full pipeline control

The most powerful tier. Receives the `RequestInterface` and a `$next` callable that executes the rest of the pipeline. Calling `$next($request)` returns a `PromiseInterface<ResponseInterface>`. The interceptor can modify the request before dispatching, modify the response after, short-circuit without calling `$next`, or retry by calling `$next` multiple times:

```php
$client = Http::client()->intercept(
    function (RequestInterface $request, callable $next): PromiseInterface {
        $token = await(TokenStore::getOrRefresh());
        $request = $request->withToken($token);

        $response = await($next($request));

        if ($response->failed()) {
            logger()->error('HTTP error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        }

        return $response;
    }
);
```

Returning a `Response` directly short-circuits the entire pipeline, which is useful for caching:

```php
$client = Http::client()->intercept(
    function (RequestInterface $request, callable $next): PromiseInterface {
        $cacheKey = md5((string) $request->getUri());

        if ($cached = Cache::get($cacheKey)) {
            return Promise::resolved($cached);
        }

        $response = await($next($request));

        if ($response->successful()) {
            Cache::set($cacheKey, $response, ttl: 60);
        }

        return $response;
    }
);
```

### Interceptor ordering

Interceptors execute in **registration order**. The first registered interceptor wraps the outermost layer; the last registered interceptor runs closest to the actual HTTP dispatch:

```php
$client = Http::client()
    ->interceptRequest(fn($r) => $r->withHeader('X-Auth', $token))    // runs 1st on request
    ->interceptRequest(fn($r) => $r->withHeader('X-Trace', $traceId)) // runs 2nd on request
    ->interceptResponse(fn($r) => logResponse($r));                    // runs on response
```

### Shared interceptor stacks

Interceptors registered on a base instance are inherited by every clone:

```php
$apiClient = Http::client()
    ->withToken($token)
    ->intercept($authRefreshMiddleware)
    ->intercept($loggingMiddleware)
    ->timeout(30);

await($apiClient->get('/users'));
await($apiClient->post('/orders', $data));
```

### Throwing on 4xx and 5xx with an interceptor

```php
use Hibla\HttpClient\Exceptions\ClientException;
use Hibla\HttpClient\Exceptions\ServerException;

$client = Http::client()->interceptResponse(function (ResponseInterface $response): ResponseInterface {
    if ($response->clientError()) {
        throw new ClientException(
            message:         "HTTP {$response->status()} Client Error",
            statusCode:      $response->status(),
            responseHeaders: $response->headers(),
        );
    }

    if ($response->serverError()) {
        throw new ServerException(
            message:         "HTTP {$response->status()} Server Error",
            statusCode:      $response->status(),
            responseHeaders: $response->headers(),
        );
    }

    return $response;
});
```

```php
try {
    $response = await($client->get('https://api.example.com/users/999'));
} catch (ClientException $e) {
    echo "Client error {$e->getStatusCode()}: {$e->getMessage()}\n";
} catch (ServerException $e) {
    echo "Server error {$e->getStatusCode()}: {$e->getMessage()}\n";
}
```

---

## Streaming

### Streaming responses

`stream()` returns a `PromiseInterface<StreamingResponseInterface>` that resolves **as soon as the response headers are received**, before any body data arrives. The body is never buffered; it is consumed incrementally after the promise resolves.

This means you can inspect the status code and headers before committing to reading the body, and abort early by simply not reading further:

> Note the streaming response dont fully use the Full Stream Api of `hibla/stream` due to compatibility reasons with loop drivers like on ext-uv which are not compatible with php file and temp stream and it uses custom implementation that implement `PromiseReadableInterface` without reimplementing the full stream api.

```php
$response = await(Http::stream('https://api.example.com/large-export'));

if ($response->status() !== 200) {
    echo "Unexpected status: " . $response->status();
    return;
}

// Now consume the body
```

> **Timeout:** The operation timeout is disabled by default for streaming requests. The connection timeout still applies. Call `timeout()` explicitly if you need to cap total transfer time.

There are two ways to consume a streaming response body: the **pull model** and the **push model**. They are not mutually exclusive and you can use both on the same stream simultaneously.

### Pull model: async incremental reads

In the pull model your code drives the read loop by awaiting each chunk explicitly. This gives you full control: you can pause between reads, inspect each chunk before deciding whether to continue, apply backpressure, or break out of the loop at any point.

Use the pull model when you need to process data conditionally, enforce memory limits, or react to the content of each chunk:

> You can check out [Promise readable Api](https://github.com/hiblaphp/stream?tab=readme-ov-file#reading-data)
```php
$response = await(Http::stream('https://api.example.com/large-export'));

// Read fixed-size chunks
while (!$response->eof()) {
    $chunk = await($response->readAsync(8192));
    if ($chunk === null) break;
    processChunk($chunk);
}

// Read line by line — ideal for NDJSON, CSV, log streams
while (true) {
    $line = await($response->readLineAsync());
    if ($line === null) break;
    handleRecord(json_decode($line, true));
}

// Read the entire body at once with a memory cap
$body = await($response->readAllAsync(maxLength: 10 * 1024 * 1024)); // 10 MB cap
```

### Push model: chunk callback

In the push model you provide an `$onChunk` callback to `stream()` and the library calls it for you as each chunk arrives. You do not manage a read loop; the event loop drives delivery automatically.

Use the push model when you want the simplest possible integration and do not need to pause, inspect, or conditionally stop the stream. It is well suited for piping data directly to another destination such as a file, a socket, or an output buffer, where every chunk should always be forwarded:

```php
$output = fopen('/tmp/export.csv', 'w');

$response = await(Http::stream(
    'https://api.example.com/export.csv',
    function (string $chunk) use ($output) {
        fwrite($output, $chunk);
    }
));

fclose($output);
```

The callback receives raw string chunks as they arrive from cURL. Chunk size is determined by the transport and is not guaranteed to align with any application-level boundaries such as newlines or JSON objects.

### Combining push and pull

The push and pull models are not mutually exclusive. You can pass an `$onChunk` callback **and** call `readAsync()` on the same response, and chunks will be delivered to both. This is useful when you need to forward a stream to one destination while simultaneously inspecting or parsing it:

```php
$pushLog = [];
$pullLog = [];

$response = await(Http::stream(
    'https://httpbin.org/stream/5',
    function (string $chunk) use (&$pushLog) {
        // Push: receives raw chunks as they arrive
        $pushLog[] = $chunk;
    }
));

// Pull: read the same data line by line
while (true) {
    $line = await($response->readLineAsync());
    if ($line === null) break;
    $pullLog[] = $line;
}

// Both approaches receive the same bytes
assert(strlen(implode('', $pushLog)) === strlen(implode('', $pullLog)));
```

In the example above the push callback fires for each raw chunk (potentially multiple chunks per line), while the pull loop processes the same data as complete newline-delimited records. Content parity is maintained between both approaches.

**Choosing between pull and push:**

| | Pull | Push |
|---|---|---|
| Control over read timing | Yes — you await each chunk | No — the event loop drives delivery |
| Conditional early exit | Yes — break out of the loop | No — all chunks are always delivered |
| Backpressure | Yes — delay the next `await` | No |
| Memory management | Explicit — you decide chunk size | Automatic — chunk size is transport-defined |
| Best for | Conditional processing, parsers, protocols | Piping, forwarding, simple fan-out |

Stream a POST request:

```php
$response = await(
    Http::client()
        ->withJson(['query' => 'SELECT * FROM logs'])
        ->stream('https://api.example.com/query/stream')
);
```

### File download

`download()` writes the response body directly to disk without buffering in memory. The operation timeout is disabled by default; only the connection timeout applies:

```php
$result = await(Http::download(
    'https://files.example.com/report.pdf',
    '/tmp/report.pdf'
));

echo $result['file'];   // '/tmp/report.pdf'
echo $result['status']; // 200
echo $result['size'];   // bytes written
```

Track progress:

```php
use Hibla\HttpClient\ValueObjects\DownloadProgress;

$result = await(Http::download(
    'https://files.example.com/archive.zip',
    '/tmp/archive.zip',
    function (DownloadProgress $progress) {
        printf("%.1f%%\n", $progress->percent);
    }
));
```

The resolved array shape:

```php
[
    'file'             => string,       // destination path
    'status'           => int,          // HTTP status code
    'headers'          => array,        // response headers
    'protocol_version' => string|null,  // negotiated HTTP version
    'size'             => int|false,    // bytes written
]
```

### File upload

`upload()` reads the source file in chunks using a non-buffered approach, keeping memory usage flat regardless of file size. The operation timeout is disabled by default:

```php
$result = await(Http::upload(
    'https://storage.example.com/files',
    '/path/to/large-file.zip'
));

echo $result['status']; // 201
```

Track progress:

```php
use Hibla\HttpClient\ValueObjects\UploadProgress;

$result = await(Http::upload(
    'https://storage.example.com/files',
    '/path/to/large-file.zip',
    function (UploadProgress $progress) {
        printf("Uploaded %.1f%%\n", $progress->percent);
    }
));
```

By default `upload()` uses the PUT method. Override it via `withMethod()`:

```php
$result = await(
    Http::client()
        ->withMethod('POST')
        ->upload('https://storage.example.com/files', '/path/to/file.zip')
);
```

The resolved array shape:

```php
[
    'url'              => string,       // target URL
    'status'           => int,          // HTTP status code
    'headers'          => array,        // response headers
    'protocol_version' => string|null,  // negotiated HTTP version
]
```

---

## Server-Sent Events

### SSE basic usage

`sse()` returns a fluent `SSEBuilderInterface`. Call `connect()` to open the connection.

The following request headers are set automatically on every SSE connection:

```
Accept: text/event-stream
Cache-Control: no-cache
Connection: keep-alive
```

The returned promise resolves **as soon as the server completes the HTTP handshake**, once a 2xx status and response headers have been received. The connection remains open and events continue to arrive after resolution; the promise does not wait for the stream to close. If the server responds with a non-2xx status, the promise rejects with an `HttpStreamException` at handshake time and no events will be delivered.

The operation timeout is **disabled by default** for SSE connections. The connection timeout still applies to the initial handshake:

```php
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEControl;

$promise = Http::client()
    ->withToken($token)
    ->sse('https://api.example.com/events')
    ->onEvent(function (SSEEvent $event, SSEControl $control) {
        echo $event->data . "\n";

        if ($event->getType() === 'done') {
            $control->cancel();
        }
    })
    ->onError(function (\Throwable $e) {
        echo "Connection error: " . $e->getMessage() . "\n";
    })
    ->connect();

// Resolves immediately after the handshake — events are already flowing
$connection = await($promise);

// Close from outside
$connection->close();
// or
$promise->cancel();
```

`SSEEvent` properties:

```php
$event->id;        // ?string — event ID
$event->event;     // ?string — event type
$event->data;      // ?string — event payload
$event->retry;     // ?int   — server-advised reconnect delay in ms
$event->rawFields; // array  — all raw parsed fields

$event->getType();     // returns event type, defaulting to 'message'
$event->isKeepAlive(); // true when data is null or empty
$event->toArray();     // full array representation
```

### SSE data formats

By default the `onEvent` callback receives a full `SSEEvent` object. Use `withDataFormat()` to change what value is passed to the callback:

```php
use Hibla\HttpClient\SSE\SSEDataFormat;
```

| Format | Enum case | Callback receives | Use when |
|---|---|---|---|
| Full event object (default) | `SSEDataFormat::Event` | `SSEEvent` | You need access to all event fields: `id`, `event`, `data`, `retry`, `rawFields` |
| Decoded JSON data | `SSEDataFormat::DecodedJson` | `array\|string` — JSON-decoded payload, or the raw string if the data is not valid JSON | Your server sends JSON payloads and you only care about the data field |
| Full event as array | `SSEDataFormat::Array` | `array` — the full event via `SSEEvent::toArray()`, with the `data` field automatically decoded from JSON if valid | You want all event fields without working with the `SSEEvent` object directly |
| Raw data string | `SSEDataFormat::Raw` | `string` — the raw, unprocessed data field value | You need to handle deserialization yourself, or the payload is not JSON |

```php
// SSEDataFormat::Event — full SSEEvent object (default)
Http::sse('https://api.example.com/events')
    ->withDataFormat(SSEDataFormat::Event)
    ->onEvent(function (SSEEvent $event, SSEControl $control) {
        echo $event->id;    // event ID
        echo $event->event; // event type
        echo $event->data;  // raw payload string
    })
    ->connect();

// SSEDataFormat::DecodedJson — JSON-decoded payload
// Falls back to raw string when the data field is not valid JSON
Http::sse('https://api.example.com/events')
    ->withDataFormat(SSEDataFormat::DecodedJson)
    ->onEvent(function (array|string $data, SSEControl $control) {
        // $data is already decoded — no json_decode() needed
        echo $data['userId'];
    })
    ->connect();

// SSEDataFormat::Array — full event as array, data auto-decoded from JSON
Http::sse('https://api.example.com/events')
    ->withDataFormat(SSEDataFormat::Array)
    ->onEvent(function (array $event, SSEControl $control) {
        echo $event['id'];             // event ID
        echo $event['event'];          // event type
        echo $event['data']['userId']; // data is already decoded
    })
    ->connect();

// SSEDataFormat::Raw — raw data string, no decoding
Http::sse('https://api.example.com/events')
    ->withDataFormat(SSEDataFormat::Raw)
    ->onEvent(function (string $data, SSEControl $control) {
        $decoded = json_decode($data, true); // you handle decoding
    })
    ->connect();
```

### Transforming events with `map()`

`map()` applies a transformation to each event value **after** `withDataFormat()` processes it but **before** `onEvent` receives it. This is the right place to convert raw event data into typed objects, keeping your `onEvent` callback clean and strongly typed.

```php
Http::sse('https://api.example.com/events')
    ->withDataFormat(SSEDataFormat::DecodedJson)
    ->map(fn(array $data) => new UserEvent($data))
    ->onEvent(function (UserEvent $event, SSEControl $control) {
        handleUserEvent($event);
    })
    ->connect();
```

Because `map()` runs after format conversion, the type you receive in the mapper matches the format you configured. With `SSEDataFormat::DecodedJson` the mapper receives an `array` (or `string` for non-JSON payloads); with `SSEDataFormat::Raw` it receives a `string`; with `SSEDataFormat::Event` it receives an `SSEEvent`.

```php
// Map from raw string to a domain model
Http::sse('https://api.example.com/events')
    ->withDataFormat(SSEDataFormat::Raw)
    ->map(fn(string $raw) => Order::fromJson($raw))
    ->onEvent(function (Order $order, SSEControl $control) {
        $this->orderRepository->save($order);

        if ($order->isComplete()) {
            $control->cancel();
        }
    })
    ->connect();
```

`map()` returns a new builder instance and is fully composable with all other SSE builder methods including `reconnect()`, `withReconnectConfig()`, and `onError()`. The mapper callable is inherited by clones, so a shared base can be specialized without repeating the transformation:

```php
$base = Http::client()
    ->withToken($token)
    ->sse('https://api.example.com/stream')
    ->withDataFormat(SSEDataFormat::DecodedJson)
    ->map(fn(array $data) => DomainEvent::fromArray($data));

// Two independent connections from the same base — mapper is applied to both
$streamA = $base->onEvent(fn(DomainEvent $e) => handleA($e))->connect();
$streamB = $base->onEvent(fn(DomainEvent $e) => handleB($e))->connect();
```

### SSE reconnection

Enable automatic reconnection with exponential backoff:

```php
Http::sse('https://api.example.com/events')
    ->reconnect(
        maxAttempts:       10,
        initialDelay:      1.0,
        maxDelay:          30.0,
        backoffMultiplier: 2.0,
        jitter:            true,
    )
    ->onEvent(fn($event, $ctrl) => handleEvent($event))
    ->connect();
```

Full control with `SSEReconnectConfig`:

```php
use Hibla\HttpClient\SSE\SSEReconnectConfig;

Http::sse('https://api.example.com/events')
    ->withReconnectConfig(new SSEReconnectConfig(
        enabled:              true,
        maxAttempts:          10,
        initialDelay:         1.0,
        maxDelay:             30.0,
        backoffMultiplier:    2.0,
        jitter:               true,
        retryableErrors:      ['Connection reset'],
        retryableStatusCodes: [503],
        onReconnect: function (int $attempt, float $delay, \Exception $error): void {
            logger()->warning("SSE reconnecting", compact('attempt', 'delay'));
        },
        shouldReconnect: function (\Exception $e): bool {
            return !($e instanceof AuthenticationException);
        },
    ))
    ->connect();
```

The `Last-Event-ID` header is forwarded automatically on reconnection so the server can resume the stream from the correct position. Disable reconnection explicitly:

```php
Http::sse('https://api.example.com/events')
    ->withoutReconnection()
    ->connect();
```

### Cancelling an SSE connection

From within the callback via `SSEControl`:

```php
->onEvent(function (SSEEvent $event, SSEControl $control) {
    if ($event->data === '[DONE]') {
        $control->cancel();
    }
})
```

From outside via the connection object or the promise:

```php
$promise    = Http::sse('...')->onEvent(...)->connect();
$connection = await($promise);

$connection->close();
// or
$promise->cancel();
```

---

## URI template parameters

URI templates support `{param}` (percent-encoded) and `{+param}` (reserved, where special characters are preserved):

```php
$response = await(
    Http::client()
        ->withUrlParameter('version', 'v2')
        ->withUrlParameter('userId', 42)
        ->get('https://api.example.com/{version}/users/{userId}')
);
// Requests: GET https://api.example.com/v2/users/42

Http::client()
    ->withUrlParameters(['version' => 'v2', 'userId' => 42])
    ->get('https://api.example.com/{version}/users/{userId}');

// Reserved expansion — slashes and special characters are not percent-encoded
Http::client()
    ->withUrlParameter('path', 'reports/2024/q4')
    ->get('https://api.example.com/files/{+path}');
// Requests: GET https://api.example.com/files/reports/2024/q4
```

Parameters with no corresponding placeholder in the URL are silently ignored.

---

## User agent

By default, every request is sent with a `User-Agent` header in the format:

```
hibla-http-client/{version} PHP/{phpVersion}
```

For example: `hibla-http-client/1.2.0 PHP/8.4.1`.

Override it per request with `withUserAgent()`:

```php
Http::client()->withUserAgent('MyApp/1.0 (contact@example.com)');
```

Or set it once on a shared base client so all derived requests inherit it:

```php
$client = Http::client()->withUserAgent('MyApp/1.0');
```

---

## Advanced usage

### Promise combinators & Structured Concurrency

Every request made with Hibla returns a `PromiseInterface`. This allows you to use the static methods on `Hibla\Promise\Promise` to manage complex request groups. Hibla enforces **Structured Concurrency**: when a collection of requests is cancelled or one fails, the library automatically and synchronously cancels all pending sibling requests to prevent resource leaks.

#### Concurrent execution with `all()`
Executes multiple requests concurrently. Resolves only when all requests succeed. If any single request fails, all other in-flight requests are **automatically cancelled** synchronously.

```php
use Hibla\Promise\Promise;
use function Hibla\await;

$promises = [
    'user'    => Http::get('https://api.example.com/user/1'),
    'posts'   => Http::get('https://api.example.com/user/1/posts'),
];

// Resolves with an associative array of Response objects
$results = await(Promise::all($promises));
```

#### Sliding window concurrency with `concurrent()`
Maintains a fixed number of active requests. If you have 100 tasks and set concurrency to 5, it will keep exactly 5 requests in-flight at all times until the queue is empty.

> **Tasks must be callables.**
> Items in the collection must be factory callables that return a promise (e.g., `fn() => Http::get(...)`). This allows the library to control exactly when each request starts. Passing pre-instantiated promises will result in a `RuntimeException`, as those requests would already be running outside of the concurrency control.

```php
$tasks = [
    fn() => Http::get('https://api.example.com/job/1'),
    fn() => Http::get('https://api.example.com/job/2'),
];

// Process the whole list but only 5 at a time
$results = await(Promise::concurrent($tasks, concurrency: 5));
```

#### Block-based execution with `batch()`
Processes tasks in sequential "blocks." The entire first batch must complete before the second batch starts. This is useful for rate-limited APIs where you need a clean break between groups of requests. 

> Like `concurrent()`, items passed to `batch()` must be callables that return a promise.

```php
// Processes in blocks of 10. Wait for all 10 to finish, then start the next 10.
$results = await(Promise::batch($tasks, batchSize: 10));
```

#### Resilient execution with `allSettled()`, `concurrentSettled()`, and `batchSettled()`
These variants wait for every request to complete regardless of success or failure. They return `SettledResult` objects containing either the `Response` or the `Exception`.

```php
$results = await(Promise::concurrentSettled($tasks, concurrency: 5));

foreach ($results as $result) {
    if ($result->isFulfilled()) {
        echo "Success: " . $result->value->status();
    } elseif ($result->isRejected()) {
        echo "Error: " . $result->reason->getMessage();
    }
}
```

#### High-performance concurrent mapping with `map()`
The `map()` utility is the most efficient way to transform an iterable (like a Generator) into API responses. It pulls items lazily and processes them concurrently up to the specified limit.

```php
$urls = [/* thousands of URLs */];

$responses = await(Promise::map($urls, function (string $url) {
    return Http::get($url);
}, concurrency: 10));
```

#### First-to-finish with `any()` and `race()`
*   **`any()`**: Resolves as soon as the **first** request succeeds. Remaining pending requests are cancelled immediately.
*   **`race()`**: Settles as soon as the **first** request settles (fulfills or rejects). Remaining requests are cancelled.

#### Memory-safe side effects with `forEach()`
Ideal for triggering massive amounts of work (like webhooks) where you do not need to capture response bodies. It discards results immediately to keep memory usage flat (O(concurrency)).

```php
$webhooks = getWebhookGenerator(); 

// Trigger 10,000 webhooks, 50 at a time, with flat RAM usage
await(Promise::forEach($webhooks, function ($url) {
    return Http::post($url, ['event' => 'ping']);
}, concurrency: 50));
```

#### Sequential dependency with `reduce()`
Use `reduce()` when requests must be made in a strict sequential order, where each request depends on the result of the previous one.

### Custom transport handlers

Hibla defaults to a cURL-backed handler, but you can swap the entire execution engine by providing an implementation of `HttpHandlerInterface`. This allows you to use alternative transports like native sockets, Swoole, or custom mocking engines.

```php
use Hibla\HttpClient\Interfaces\Handler\HttpHandlerInterface;
use Hibla\HttpClient\Http;

class MyCustomHandler implements HttpHandlerInterface 
{
    // Implement sendRequest, stream, download, upload, and sse
}

// Inject the custom engine into the client
$client = Http::client()->withHandler(new MyCustomHandler());

// All requests through this $client now use MyCustomHandler
$response = await($client->get('https://example.com'));
```

### Custom transport options

The library uses a "Builder" to translate high-level client settings into low-level transport data. By default, it uses `CurlOptionsBuilder` to create cURL arrays. If you are using a custom `HttpHandler`, you can provide a custom builder to change how requests are constructed.

```php
use Hibla\HttpClient\Interfaces\Handler\TransportOptionsBuilderInterface;
use Hibla\HttpClient\ValueObjects\ClientOptions;

class MyCustomBuilder implements TransportOptionsBuilderInterface
{
    public function build(ClientOptions $options): mixed
    {
        // Translate Hibla's ClientOptions into your custom engine's format
        return [
            'method' => $options->method,
            'url'    => $options->url,
            // ...
        ];
    }
    
    // Implement buildForStreaming, buildForDownload, etc.
}

$client = Http::client()
    ->withHandler(new MyCustomHandler())
    ->withTransportOptionsBuilder(new MyCustomBuilder());
```

---

## Testing

The library integrates with a separate testing package that provides a full mock and assertion API without requiring any changes to application code:

```bash
composer require --dev hiblaphp/http-client-testing
```

Enable testing mode to intercept all requests made through `Http::client()`, `Http::fetch()`, and `new HttpClient()`:

```php
use Hibla\HttpClient\Http;

Http::startTesting();

Http::mock('GET')
    ->url('https://api.example.com/users')
    ->respondWith(200, ['users' => [['id' => 1, 'name' => 'Alice']]]);

$response = await(Http::get('https://api.example.com/users'));

Http::assertRequestMade('GET', 'https://api.example.com/users');

Http::stopTesting();
```

Call `Http::resetTesting()` between tests to clear recorded requests and mocked responses without disabling testing mode, and `Http::stopTesting()` in `tearDown()` to return to normal HTTP operations:

```php
protected function setUp(): void
{
    Http::startTesting();
}

protected function tearDown(): void
{
    Http::stopTesting();
}
```

See the [hiblaphp/http-client-testing](https://github.com/hiblaphp/http-client-testing) documentation for the full API including request, header, cookie, download, upload, stream, and SSE assertions.

---

## API Reference

### `Http` facade

| Method | Description |
|--------|-------------|
| `Http::client()` | Create a new fluent builder instance |
| `Http::fetch(string $url, array $options)` | fetch()-style request |
| `Http::get(string $url, array $query)` | GET request |
| `Http::post(string $url, array $data)` | POST request |
| `Http::put(string $url, array $data)` | PUT request |
| `Http::patch(string $url, array $data)` | PATCH request |
| `Http::delete(string $url)` | DELETE request |
| `Http::head(string $url)` | HEAD request |
| `Http::options(string $url)` | OPTIONS request |
| `Http::stream(string $url, ?callable $onChunk)` | Streaming response |
| `Http::download(string $url, string $dest, ?callable $onProgress)` | File download |
| `Http::upload(string $url, string $src, ?callable $onProgress)` | File upload |
| `Http::sse(string $url)` | SSE builder |

### `RequestInterface`

The interface representing an in-flight request within the interceptor pipeline. It provides full PSR-7 compatibility along with Hibla's fluent mutation methods. Since the request is immutable, all `with*` methods return a new instance.

#### HTTP Method & URI
| Method | Return Type | Description |
|--------|-------------|-------------|
| `getMethod()` | `string` | Returns the HTTP method (e.g., `'GET'`). |
| `withMethod(string $method)` | `static` | Returns a clone with the provided method. |
| `getUri()` | `UriInterface` | Returns the PSR-7 URI instance. |
| `withUri(UriInterface $uri, bool $preserveHost = false)` | `static` | Returns a clone with the provided URI. |
| `getRequestTarget()` | `string` | Returns the message's request-target string. |
| `withRequestTarget(string $target)` | `static` | Returns a clone with the specific request-target. |

#### Headers
| Method | Return Type | Description |
|--------|-------------|-------------|
| `getHeaders()` | `array` | Retrieves all message header values. |
| `hasHeader(string $name)` | `bool` | Checks if a header exists (case-insensitive). |
| `getHeader(string $name)` | `string[]` | Retrieves a message header value as an array. |
| `getHeaderLine(string $name)` | `string` | Retrieves a comma-separated string of header values. |
| `withHeader(string $name, $value)` | `static` | Returns a clone with the specified header set. |
| `withAddedHeader(string $name, $value)` | `static` | Returns a clone with the value appended to the header. |
| `withoutHeader(string $name)` | `static` | Returns a clone with the specified header removed. |
| `withHeaders(array $headers)` | `static` | Returns a clone with multiple headers merged. |
| `contentType(string $type)` | `static` | Shortcut to set the `Content-Type` header. |
| `accept(string $type)` | `static` | Shortcut to set the `Accept` header. |
| `asJson()`, `asForm()`, `asXml()` | `static` | Shortcuts to set standard `Content-Type` headers. |
| `withUserAgent(string $userAgent)` | `static` | Returns a clone with a custom User-Agent. |

#### Authentication
| Method | Return Type | Description |
|--------|-------------|-------------|
| `withToken(string $token, string $type = 'Bearer')` | `static` | Sets the `Authorization` header with a token. |
| `withBasicAuth(string $u, string $p)` | `static` | Configures the request for HTTP Basic Auth. |
| `withDigestAuth(string $u, string $p)` | `static` | Configures the request for HTTP Digest Auth. |

#### Body
| Method | Return Type | Description |
|--------|-------------|-------------|
| `getBody()` | `StreamInterface` | Gets the body of the message. |
| `withBody(StreamInterface $body)` | `static` | Returns a clone with the specified message body. |
| `body(string $content)` | `static` | Returns a clone with the raw string body. |
| `withJson(array $data)` | `static` | Encodes data to JSON and sets the `Content-Type`. |
| `withForm(array $data)` | `static` | URL-encodes data and sets the `Content-Type`. |
| `withXml(string\|SimpleXMLElement $xml)` | `static` | Sets the body as XML and sets the `Content-Type`. |
| `withMultipart(array $data)` | `static` | Sets the body for multipart form data. |

#### Cookies
| Method | Return Type | Description |
|--------|-------------|-------------|
| `withCookie(string $name, string $value)` | `static` | Adds a one-shot cookie to the request header. |
| `withCookies(array $cookies)` | `static` | Adds multiple one-shot cookies to the request. |
| `withCookieJar()` | `static` | Enables an automatic in-memory cookie jar. |
| `useCookieJar(CookieJarInterface $jar)` | `static` | Returns a clone using the specified cookie jar. |
| `clearCookies()` | `static` | Clears the active jar and removes the `Cookie` header. |
| `getCookieJar()` | `CookieJarInterface\|null` | Returns the currently active jar, if any. |
| `cookieWithAttributes(string $name, string $value, array $attrs)` | `static` | Manually adds a cookie with full attribute control to the jar. |

#### Protocol
| Method | Return Type | Description |
|--------|-------------|-------------|
| `getProtocolVersion()` | `string` | Returns the HTTP protocol version (e.g., `'1.1'`). |
| `withProtocolVersion(string $v)` | `static` | Returns a clone with the specified protocol version. |


---

### Builder methods

| Method | Description |
|--------|-------------|
| `withHeader(string $name, $value)` | Set a header |
| `withAddedHeader(string $name, $value)` | Append a header value |
| `withoutHeader(string $name)` | Remove a header |
| `withHeaders(array $headers)` | Merge multiple headers |
| `contentType(string $type)` | Set Content-Type |
| `accept(string $type)` | Set Accept |
| `asJson()` | Content-Type: application/json |
| `asForm()` | Content-Type: application/x-www-form-urlencoded |
| `asXml()` | Content-Type: application/xml |
| `withUserAgent(string $ua)` | Set User-Agent |
| `withToken(string $token, string $type)` | Bearer/custom token auth |
| `withBasicAuth(string $u, string $p)` | HTTP Basic auth |
| `withDigestAuth(string $u, string $p)` | HTTP Digest auth |
| `body(string $content)` | Raw body |
| `withJson(array $data)` | JSON body |
| `withForm(array $data)` | Form-encoded body |
| `withMultipart(array $data)` | Multipart body |
| `withXml(string\|\SimpleXMLElement $xml)` | XML body |
| `withFile(string $name, mixed $file, ...)` | Attach a file |
| `withFiles(array $files)` | Attach multiple files |
| `multipartWithFiles(array $data, array $files)` | Fields and files together |
| `withCookie(string $name, string $value)` | One-shot cookie |
| `withCookies(array $cookies)` | Multiple one-shot cookies |
| `withCookieJar()` | Enable in-memory cookie jar |
| `useCookieJar(CookieJarInterface $jar)` | Use existing jar |
| `clearCookies()` | Clear all cookies from jar |
| `cookieWithAttributes(string $name, string $value, array $attrs)` | Cookie with full attributes |
| `timeout(int $seconds)` | Total timeout |
| `connectTimeout(int $seconds)` | Connection timeout |
| `redirects(bool $follow, int $max)` | Redirect behavior |
| `verifySSL(bool $verify)` | SSL verification |
| `httpVersion(string $version)` | Protocol version string |
| `http1()` | Force HTTP/1.1 |
| `http2()` | Negotiate HTTP/2 |
| `http3()` | Negotiate HTTP/3 |
| `retry(int $max, float $delay, float $multiplier)` | Enable retry |
| `withRetryConfig(RetryConfig $config)` | Full retry config |
| `withoutRetries()` | Disable retry |
| `withProxy(string $host, int $port, ...)` | HTTP proxy |
| `withSocks4Proxy(string $host, int $port, ...)` | SOCKS4 proxy |
| `withSocks5Proxy(string $host, int $port, ...)` | SOCKS5 proxy |
| `withProxyConfig(ProxyConfig $config)` | Proxy value object |
| `withoutProxy()` | Bypass proxy |
| `withCurlOption(int $opt, mixed $value)` | Single raw cURL option |
| `withCurlOptions(array $opts)` | Multiple raw cURL options |
| `withUrlParameter(string $key, mixed $value)` | URI template parameter |
| `withUrlParameters(array $params)` | Multiple URI template parameters |
| `interceptRequest(callable $cb)` | Request interceptor |
| `interceptResponse(callable $cb)` | Response interceptor |
| `intercept(callable $middleware)` | Full pipeline interceptor |
| `send(string $method, string $url)` | Dispatch with arbitrary method |
| `stream(string $url, ?callable $onChunk)` | Stream response body |
| `download(string $url, string $dest, ...)` | Download file to disk |
| `upload(string $url, string $src, ...)` | Upload file from disk |
| `sse(string $url)` | SSE builder |

### `SSEBuilderInterface`

| Method | Description |
|--------|-------------|
| `onEvent(callable $cb)` | Callback invoked for each event. Receives the value shaped by `withDataFormat()`, then passed through `map()` if set. Second argument is `SSEControl`. |
| `onError(callable $cb)` | Callback invoked on connection errors. Receives a `\Throwable`. |
| `withDataFormat(SSEDataFormat $format)` | Set the format of data passed to `onEvent`. |
| `map(callable $mapper)` | Transform each event value after format conversion but before `onEvent`. Useful for mapping to typed objects. |
| `reconnect(...)` | Enable automatic reconnection with exponential backoff. |
| `withReconnectConfig(SSEReconnectConfig $config)` | Provide a fully custom reconnection configuration. |
| `withoutReconnection()` | Explicitly disable reconnection. |
| `connect()` | Open the connection and return `PromiseInterface<SSEResponse>`. |

### `ResponseInterface`

| Method | Return type | Description |
|--------|-------------|-------------|
| `body()` | `string` | Full response body |
| `json(?string $key, mixed $default)` | `mixed` | Decoded JSON, optionally at a dot-notation path |
| `xml()` | `SimpleXMLElement\|null` | Decoded XML |
| `status()` | `int` | HTTP status code |
| `headers()` | `array<string, string>` | All headers, lowercase keys |
| `header(string $name)` | `string\|null` | Single header value |
| `successful()` | `bool` | 2xx status |
| `failed()` | `bool` | 4xx or 5xx status |
| `clientError()` | `bool` | 4xx status |
| `serverError()` | `bool` | 5xx status |
| `getHttpVersion()` | `string\|null` | Negotiated HTTP version |
| `getHttpVersionString()` | `string` | Full version string, e.g. `HTTP/2` |

### `CookieJarInterface`

The contract all cookie jar implementations must satisfy. The built-in `CookieJar` is the default in-memory implementation.

| Method | Signature | Description |
|--------|-----------|-------------|
| `setCookie` | `(Cookie $cookie): void` | Add or replace a cookie. When a cookie with the same name, domain, and path already exists, it is replaced and its original creation time is preserved per RFC 6265 section 5.3. |
| `getCookies` | `(string $domain, string $path, bool $isSecure): Cookie[]` | Return all cookies applicable to the given request context. Implementations must apply domain matching (including subdomain rules), path prefix matching, and the `Secure` flag. Use `Cookie::matches()` to delegate this logic. |
| `getAllCookies` | `(): Cookie[]` | Return every cookie in the jar regardless of scope. Useful for serialization, debugging, and test assertions. |
| `getCookieHeader` | `(string $domain, string $path, bool $isSecure): string` | Build the value for a `Cookie` request header scoped to the given context. Returns an empty string when no cookies match. |
| `clearExpired` | `(): void` | Remove all cookies whose expiry date is in the past. Should be called periodically in long-running processes to prevent unbounded jar growth. |
| `clear` | `(): void` | Remove all cookies from the jar unconditionally. |

---

## Exceptions

All exceptions thrown by this library implement `RequestExceptionInterface`, making it the single catch-all type for callers that do not need to distinguish failure categories.

**Important:** exceptions are thrown only for transport-level failures. Completed HTTP exchanges with 4xx or 5xx status codes resolve the promise normally with a `Response`. To throw on HTTP errors, see [Throwing on 4xx and 5xx with an interceptor](#throwing-on-4xx-and-5xx-with-an-interceptor).

| Exception | When thrown |
|-----------|-------------|
| `NetworkException` | Transport-level failure: connection refused, DNS failure, SSL error, network unreachable |
| `TimeoutException` | Request or connection timeout exceeded. Extends `NetworkException`. |
| `ClientException` | Not thrown automatically. Used when building throw-on-error interceptors. |
| `ServerException` | Not thrown automatically. Used when building throw-on-error interceptors. |
| `HttpStreamException` | Streaming-specific errors: file open failure, stream closed unexpectedly |
| `RequestException` | Generic request errors that do not fit other categories |

```php
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Exceptions\TimeoutException;

try {
    $response = await(Http::get('https://api.example.com/users'));
} catch (TimeoutException $e) {
    if ($e->isConnectionTimeout()) {
        echo "Could not connect within {$e->getTimeout()}s\n";
    } else {
        echo "Request timed out after {$e->getTimeout()}s\n";
    }
} catch (NetworkException $e) {
    echo "Network error ({$e->getErrorType()}): {$e->getMessage()}\n";
}
```
---

## Development
```bash
git clone https://github.com/hiblaphp/promise.git
cd promise
composer install
```
```bash
./vendor/bin/pest
```
```bash
./vendor/bin/phpstan analyse
```

---

## Credits

- **API Design:** Heavily inspired by Laravel HTTP Client Api and JavaScript `fetch` API with emphasis on cancellation, async-first design, and first-class sse streaming.

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.