<?php

use Hibla\Http\Http;

require __DIR__ . '/vendor/autoload.php';

echo "🚀 Testing Hibla HTTP Client\n";
echo str_repeat("=", 50) . "\n\n";

// Test 1: Basic GET with headers
echo "1. Testing Basic GET with Custom Headers\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->header('CLIENT-ID', '123456')
        ->header('X-Custom-Header', 'test-value')
        ->userAgent("Hibla-HTTP-Client/1.0")
        ->get('https://httpbin.org/headers')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Response: " . substr($response->body(), 0, 200) . "...\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 2: JSON GET request
echo "2. Testing JSON GET Request\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->accept('application/json')
        ->get('https://jsonplaceholder.typicode.com/posts/1')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Content-Type: " . $response->header('content-type') . "\n";
    $json = $response->json();
    echo "Title: " . ($json['title'] ?? 'N/A') . "\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 3: POST with JSON body
echo "3. Testing POST with JSON Body\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->post('https://jsonplaceholder.typicode.com/posts', [
            'title' => 'Test Post',
            'body' => 'This is a test post from Hibla HTTP Client',
            'userId' => 1
        ])
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    $json = $response->json();
    echo "Created ID: " . ($json['id'] ?? 'N/A') . "\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 4: PUT request
echo "4. Testing PUT Request\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->put('https://jsonplaceholder.typicode.com/posts/1', [
            'id' => 1,
            'title' => 'Updated Post Title',
            'body' => 'Updated post body',
            'userId' => 1
        ])
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    $json = $response->json();
    echo "Updated Title: " . ($json['title'] ?? 'N/A') . "\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 5: DELETE request
echo "5. Testing DELETE Request\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->delete('https://jsonplaceholder.typicode.com/posts/1')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Success: " . ($response->successful() ? 'Yes' : 'No') . "\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 6: Form data POST
echo "6. Testing Form Data POST\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->form([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'message' => 'Hello from form data'
        ])
        ->post('https://httpbin.org/post')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Response: " . substr($response->body(), 0, 200) . "...\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 7: Basic Authentication
echo "7. Testing Basic Authentication\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->basicAuth('user', 'passwd')
        ->get('https://httpbin.org/basic-auth/user/passwd')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Auth Success: " . ($response->successful() ? 'Yes' : 'No') . "\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 8: Bearer Token
echo "8. Testing Bearer Token Authorization\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->bearerToken('test-token-123')
        ->get('https://httpbin.org/bearer')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Token Auth: " . ($response->successful() ? 'Success' : 'Failed') . "\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 9: Query Parameters
echo "9. Testing GET with Query Parameters\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->get('https://httpbin.org/get', [
            'param1' => 'value1',
            'param2' => 'value2',
            'search' => 'hibla http client'
        ])
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Response: " . substr($response->body(), 0, 200) . "...\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 10: Custom timeout
echo "10. Testing Custom Timeout\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->timeout(5)
        ->connectTimeout(2)
        ->get('https://httpbin.org/delay/1')  // 1 second delay
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Timeout Test: " . ($response->successful() ? 'Passed' : 'Failed') . "\n\n";
} catch (Exception $e) {
    echo "Timeout Error (expected for slow endpoints): " . $e->getMessage() . "\n\n";
}

// Test 11: Multiple Headers
echo "11. Testing Multiple Headers\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->headers([
            'X-Custom-1' => 'value1',
            'X-Custom-2' => 'value2',
            'Accept' => 'application/json'
        ])
        ->contentType('application/json')
        ->get('https://httpbin.org/headers')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Headers sent successfully\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 12: Raw body content
echo "12. Testing Raw Body Content\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->body('This is raw body content for testing')
        ->contentType('text/plain')
        ->post('https://httpbin.org/post')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "Raw body sent successfully\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 13: Testing response helper methods
echo "13. Testing Response Helper Methods\n";
echo str_repeat("-", 30) . "\n";
try {
    // Test successful response
    $response = Http::request()
        ->get('https://jsonplaceholder.typicode.com/posts/1')
        ->await();
    
    echo "Status Code: " . $response->status() . "\n";
    echo "Is Successful: " . ($response->successful() ? 'Yes' : 'No') . "\n";
    echo "Is OK: " . ($response->ok() ? 'Yes' : 'No') . "\n";
    echo "Has Failed: " . ($response->failed() ? 'Yes' : 'No') . "\n";
    
    // Test 404 response
    $notFoundResponse = Http::request()
        ->get('https://jsonplaceholder.typicode.com/posts/999999')
        ->await();
    
    echo "\n404 Response:\n";
    echo "Status Code: " . $notFoundResponse->status() . "\n";
    echo "Is Client Error: " . ($notFoundResponse->clientError() ? 'Yes' : 'No') . "\n";
    echo "Is Server Error: " . ($notFoundResponse->serverError() ? 'Yes' : 'No') . "\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Test 14: SSL verification (optional test)
echo "14. Testing SSL Configuration\n";
echo str_repeat("-", 30) . "\n";
try {
    $response = Http::request()
        ->verifySSL(true)  // Enable SSL verification
        ->get('https://httpbin.org/get')
        ->await();
    
    echo "Status: " . $response->status() . "\n";
    echo "SSL Verification: Enabled and working\n\n";
} catch (Exception $e) {
    echo "SSL Error: " . $e->getMessage() . "\n\n";
}

echo "🎉 HTTP Client Testing Complete!\n";