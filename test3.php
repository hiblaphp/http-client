<?php

use Hibla\Http\Http;
require __DIR__ . '/vendor/autoload.php';

// Let's isolate each feature to find the culprit

echo "Testing individual features:\n\n";

// Test 1: Just cookies
echo "1. Cookies only: ";
try {
    $response = Http::request()->withCookieJar()->get('https://httpbin.org/cookies')->await();
    echo "✓ Works (Status: " . $response->status() . ")\n";
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}

// Test 2: Just redirects
echo "2. Redirects only: ";
try {
    $response = Http::request()->redirects(true, 3)->get('https://httpbin.org/cookies')->await();
    echo "✓ Works (Status: " . $response->status() . ")\n";
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}

// Test 3: Just retry
echo "3. Retry only: ";
try {
    $response = Http::request()->retry(2, 0.5, 2.0)->get('https://httpbin.org/cookies')->await();
    echo "✓ Works (Status: " . $response->status() . ")\n";
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}

// Test 4: Cookies + Redirects
echo "4. Cookies + Redirects: ";
try {
    $response = Http::request()->withCookieJar()->redirects(true, 3)->get('https://httpbin.org/cookies')->await();
    echo "✓ Works (Status: " . $response->status() . ")\n";
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}

// Test 5: Cookies + Retry
echo "5. Cookies + Retry: ";
try {
    $response = Http::request()->withCookieJar()->retry(2, 0.5, 2.0)->get('https://httpbin.org/cookies')->await();
    echo "✓ Works (Status: " . $response->status() . ")\n";
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}

// Test 6: Redirects + Retry
echo "6. Redirects + Retry: ";
try {
    $response = Http::request()->redirects(true, 3)->retry(2, 0.5, 2.0)->get('https://httpbin.org/cookies')->await();
    echo "✓ Works (Status: " . $response->status() . ")\n";
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}

// Test 7: All three
echo "7. All features: ";
try {
    $response = Http::request()->withCookieJar()->redirects(true, 3)->retry(2, 0.5, 2.0)->get('https://httpbin.org/cookies')->await();
    echo "✓ Works (Status: " . $response->status() . ")\n";
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}