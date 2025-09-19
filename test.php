<?php

require_once 'vendor/autoload.php'; 

use Hibla\Http\Request;
use Hibla\Http\Handlers\HttpHandler;
use Hibla\Http\UploadedFile;
use Hibla\Http\Stream;

$handler = new HttpHandler(); 
$client = new Request($handler);

echo "Testing Hibla HTTP Client with HTTPBin\n";
echo "=====================================\n\n";

// Test 1: Basic GET Request
echo "1. Testing Basic GET Request...\n";
try {
    $response = $client->get('https://httpbin.org/get?test=123')->await();
    echo "✓ Status: " . $response->status() . "\n";
    echo "✓ Response contains test parameter: " . (str_contains($response->body(), '"test": "123"') ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 2: POST with JSON
echo "2. Testing POST with JSON...\n";
try {
    $data = ['name' => 'John Doe', 'email' => 'john@example.com'];
    $response = $client->post('https://httpbin.org/post', $data)->await();
    echo "✓ Status: " . $response->status() . "\n";
    $responseData = $response->json();
    echo "✓ JSON posted correctly: " . (isset($responseData['json']['name']) ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 3: Form Data
echo "3. Testing Form Data...\n";
try {
    $response = $client
        ->form(['username' => 'testuser', 'password' => 'secret123'])
        ->post('https://httpbin.org/post')
        ->await();
    echo "✓ Status: " . $response->status() . "\n";
    $body = $response->body();
    echo "✓ Form data posted: " . (str_contains($body, 'username') ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 4: Headers
echo "4. Testing Custom Headers...\n";
try {
    $response = $client
        ->header('X-Custom-Header', 'TestValue123')
        ->bearerToken('fake-token-for-testing')
        ->get('https://httpbin.org/headers')
        ->await();
    echo "✓ Status: " . $response->status() . "\n";
    $body = $response->body();
    echo "✓ Custom header sent: " . (str_contains($body, 'X-Custom-Header') ? 'Yes' : 'No') . "\n";
    echo "✓ Bearer token sent: " . (str_contains($body, 'Bearer fake-token-for-testing') ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 5: Basic Auth
echo "5. Testing Basic Authentication...\n";
try {
    $response = $client
        ->basicAuth('testuser', 'testpass')
        ->get('https://httpbin.org/basic-auth/testuser/testpass')
        ->await();
    echo "✓ Status: " . $response->status() . "\n";
    echo "✓ Authentication successful: " . ($response->status() === 200 ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 6: File Upload (create a test file first)
echo "6. Testing File Upload...\n";
try {
    // Create a temporary test file
    $testFilePath = sys_get_temp_dir() . '/test_upload.txt';
    file_put_contents($testFilePath, 'This is a test file for upload testing.');

    $response = $client
        ->file('testfile', $testFilePath, 'test.txt', 'text/plain')
        ->multipart(['description' => 'Test file upload'])
        ->post('https://httpbin.org/post')
        ->await();

    echo "✓ Status: " . $response->status() . "\n";
    $body = $response->body();
    echo "✓ File uploaded: " . (str_contains($body, 'testfile') ? 'Yes' : 'No') . "\n";
    echo "✓ Form data included: " . (str_contains($body, 'description') ? 'Yes' : 'No') . "\n";

    // Clean up
    if (file_exists($testFilePath)) {
        unlink($testFilePath);
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    // Clean up on error
    if (isset($testFilePath) && file_exists($testFilePath)) {
        unlink($testFilePath);
    }
}
echo "\n";

// Test 7: UploadedFile Interface
echo "7. Testing UploadedFile Interface...\n";
try {
    // Create test content
    $testContent = 'Test content for UploadedFile';
    $stream = Stream::fromString($testContent);

    $uploadedFile = new UploadedFile(
        $stream,
        strlen($testContent),
        UPLOAD_ERR_OK,
        'uploaded-test.txt',
        'text/plain'
    );

    echo "✓ UploadedFile created successfully\n";
    echo "✓ Filename: " . $uploadedFile->getClientFilename() . "\n";
    echo "✓ Size: " . $uploadedFile->getSize() . " bytes\n";
    echo "✓ Media Type: " . $uploadedFile->getClientMediaType() . "\n";
    echo "✓ Error Code: " . $uploadedFile->getError() . " (" . $uploadedFile->getErrorMessage() . ")\n";

    // Test upload with UploadedFile
    $response = $client
        ->file('uploaded_file', $uploadedFile)
        ->post('https://httpbin.org/post')
        ->await();

    echo "✓ Upload Status: " . $response->status() . "\n";
    echo "✓ File uploaded via UploadedFile: " . (str_contains($response->body(), 'uploaded_file') ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 8: Response Helper Methods
echo "8. Testing Response Helper Methods...\n";
try {
    $response = $client->get('https://httpbin.org/status/404')->await();

    echo "✓ Status: " . $response->status() . "\n";
    echo "✓ Is OK (2xx): " . ($response->ok() ? 'Yes' : 'No') . "\n";
    echo "✓ Is Successful: " . ($response->successful() ? 'Yes' : 'No') . "\n";
    echo "✓ Failed: " . ($response->failed() ? 'Yes' : 'No') . "\n";
    echo "✓ Client Error: " . ($response->clientError() ? 'Yes' : 'No') . "\n";
    echo "✓ Server Error: " . ($response->serverError() ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 9: Cookies
echo "9. Testing Cookies...\n";
try {
    // Set cookies
    $response = $client
        ->get('https://httpbin.org/cookies/set/test_cookie/test_value')
        ->await();

    echo "✓ Cookie set response status: " . $response->status() . "\n";

    // Test cookie in request
    $response = $client
        ->cookie('manual_cookie', 'manual_value')
        ->get('https://httpbin.org/cookies')
        ->await();

    echo "✓ Cookie test status: " . $response->status() . "\n";
    echo "✓ Manual cookie sent: " . (str_contains($response->body(), 'manual_cookie') ? 'Yes' : 'No') . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 10: User Agent and Custom Headers
echo "10. Testing User Agent...\n";
try {
    $response = $client
        ->userAgent('Hibla-HTTP-Client/1.0 Test-Suite')
        ->get('https://httpbin.org/user-agent')
        ->await();

    echo "✓ Status: " . $response->status() . "\n";
    $responseData = $response->json();
    echo "✓ Custom User-Agent: " . $responseData['user-agent'] . "\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

echo "Testing completed!\n";
echo "===================\n";
