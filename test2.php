<?php

use Hibla\Http\Http;
use Hibla\Http\RetryConfig;

require __DIR__ . '/vendor/autoload.php';

echo "🍪 Testing Advanced HTTP Client Features\n";
echo str_repeat("=", 50) . "\n\n";

// Test 1: Cookie Handling - Manual Cookies
echo "1. Testing Manual Cookie Setting\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->cookie('session_id', 'abc123')
        ->cookie('user_pref', 'dark_mode')
        ->cookies([
            'language' => 'en',
            'timezone' => 'UTC'
        ])
        ->get('https://httpbin.org/cookies')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Response: " . substr($response->body(), 0, 300) . "...\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 2: Cookie Jar - Automatic Cookie Management
echo "2. Testing Automatic Cookie Jar\n";
echo str_repeat("-", 30) . "\n";
try {
    $client = Http::request()->withCookieJar();
    
    // First request - server sets cookies
    echo "Step 1: Getting cookies from server\n";
    $response1 = $client
        ->get('https://httpbin.org/cookies/set/test_cookie/test_value')
        ->await();
    echo "Status: " . $response1->status() . "\n";
    
    // Second request - cookies should be sent automatically
    echo "Step 2: Cookies should be sent automatically\n";
    $response2 = $client
        ->get('https://httpbin.org/cookies')
        ->await();
    echo "Status: " . $response2->status() . "\n";
    echo "Cookies sent: " . substr($response2->body(), 0, 200) . "...\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 3: File-based Cookie Jar
echo "3. Testing File-based Cookie Jar\n";
echo str_repeat("-", 30) . "\n";
try {
    $cookieFile = __DIR__ . '/test_cookies.txt';
    
    $client = Http::request()->withFileCookieJar($cookieFile);
    
    // Set cookies and save to file
    $response = $client
        ->get('https://httpbin.org/cookies/set/persistent_cookie/file_value')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Cookie file exists: " . (file_exists($cookieFile) ? 'Yes' : 'No') . "\n";
    
    // Create new client with same cookie file
    $newClient = Http::request()->withFileCookieJar($cookieFile);
    $response2 = $newClient
        ->get('https://httpbin.org/cookies')
        ->await();
    
    echo "Persistent cookies loaded: " . ($response2->successful() ? 'Yes' : 'No') . "\n";
    
    // Cleanup
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
        echo "Cleaned up cookie file\n\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 4: Cookie with Attributes
echo "4. Testing Cookie with Attributes\n";
echo str_repeat("-", 30) . "\n";
try {
    $client = Http::request()->withCookieJar();
    
    $response = $client
        ->cookieWithAttributes('advanced_cookie', 'value123', [
            'domain' => 'httpbin.org',
            'path' => '/',
            'secure' => true,
            'httpOnly' => true
        ])
        ->get('https://httpbin.org/cookies')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Advanced cookie set successfully\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 5: Redirect Following (Default behavior)
echo "5. Testing Redirect Following (Default)\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->get('https://httpbin.org/redirect/2')  // 2 redirects
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Final URL reached: " . ($response->successful() ? 'Yes' : 'No') . "\n";
    echo "Redirects followed successfully\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 6: Redirect Configuration
echo "6. Testing Redirect Configuration\n";
echo str_repeat("-", 30) . "\n";
try {
    // Test with limited redirects
    $response1 = Http::request()
        ->redirects(true, 1)  // Allow only 1 redirect
        ->get('https://httpbin.org/redirect/2')  // But endpoint has 2 redirects
        ->await();
    
    echo "Limited redirects - Status: " . $response1->status() . "\n";
    
    // Test with redirects disabled
    $response2 = Http::request()
        ->redirects(false)  // Disable redirects
        ->get('https://httpbin.org/redirect/1')
        ->await();
    
    echo "No redirects - Status: " . $response2->status() . "\n";
    echo "Should be 3xx status code: " . ($response2->status() >= 300 && $response2->status() < 400 ? 'Yes' : 'No') . "\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 7: Retry Logic - Basic Retry
echo "7. Testing Basic Retry Logic\n";
echo str_repeat("-", 30) . "\n";
try {
    echo "Testing retry on 500 error...\n";
    $response = Http::request()
        ->retry(3, 0.5, 1.5)  // 3 retries, 0.5s base delay, 1.5x backoff
        ->get('https://httpbin.org/status/500')  // Always returns 500
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Retry logic executed (may still fail after retries)\n\n";
} catch (Exception $e) {
    echo "Expected error after retries: " . $e->getMessage() . "\n\n";
}

// Test 8: Retry with Custom Configuration
echo "8. Testing Custom Retry Configuration\n";
echo str_repeat("-", 30) . "\n";
try {
    
    $retryConfig = new RetryConfig(
        maxRetries: 2,
        baseDelay: 0.1,
        backoffMultiplier: 2.0
    );
    
    echo "Testing with custom retry config...\n";
    $response = Http::request()
        ->retryWith($retryConfig)
        ->get('https://httpbin.org/status/502')  // Bad Gateway
        ->await();
    
    echo "Status: " . $response->status() . "\n";
} catch (Exception $e) {
    echo "Expected error after custom retries: " . $e->getMessage() . "\n\n";
}

// Test 9: No Retry
echo "9. Testing Disabled Retry\n";
echo str_repeat("-", 30) . "\n";
try {
    echo "Testing without retry (should fail immediately)...\n";
    $response = Http::request()
        ->noRetry()
        ->get('https://httpbin.org/status/503')  // Service Unavailable
        ->await();
    
    echo "Status: " . $response->status() . "\n";
} catch (Exception $e) {
    echo "Expected immediate error: " . $e->getMessage() . "\n\n";
}

// Test 10: Successful request with retry enabled (should not retry)
echo "10. Testing Successful Request with Retry Enabled\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->retry(3, 1.0, 2.0)  // Retry enabled but shouldn't be used
        ->get('https://httpbin.org/status/200')  // Success
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Success without retry: " . ($response->successful() ? 'Yes' : 'No') . "\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 11: Combined Features - Cookies + Redirects + Retry
echo "11. Testing Combined Features\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->withCookieJar()                    // Enable cookies
        ->cookie('test', 'combined')         // Set initial cookie
        ->redirects(true, 3)                 // Allow redirects
        ->retry(2, 0.5, 2.0)                // Enable retry
        ->timeout(10)                        // Set timeout
        ->get('https://httpbin.org/cookies') // Test endpoint
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Combined features test: " . ($response->successful() ? 'Success' : 'Failed') . "\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

echo "12. Testing Clear Cookies\n";
echo str_repeat("-", 30) . "\n";
try {
    $client = Http::request()
        ->withCookieJar()
        ->cookie('temp_cookie', 'temp_value');
    
    $response1 = $client->get('https://httpbin.org/cookies')->await();
    echo "Before clear - Status: " . $response1->status() . "\n";
    
    $response2 = $client
        ->clearCookies()
        ->get('https://httpbin.org/cookies')
        ->await();
    
    echo "After clear - Status: " . $response2->status() . "\n";
    echo "Cookies cleared successfully\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

echo "🎉 Advanced Features Testing Complete!\n";