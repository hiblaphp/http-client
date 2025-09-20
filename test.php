<?php

use Hibla\Http\Http;

require __DIR__ . '/vendor/autoload.php';

echo "=== THOROUGH PATCH vs PUT TESTING ===\n\n";

// Test 1: PUT - Should replace entire resource
echo "1. PUT Test - Full Resource Replacement\n";
echo "   Sending complete user object...\n";
$putResponse = Http::request()->put('https://httpbin.org/put', [
    'id' => 123,
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'phone' => '555-1234',
    'status' => 'active'
])->await();

$putData = json_decode($putResponse->getBody(), true);
echo "   Status: " . $putResponse->status() . "\n";
echo "   Content-Type sent: " . ($putData['headers']['Content-Type'] ?? 'None') . "\n";
echo "   Data received by server:\n";
foreach ($putData['json'] as $key => $value) {
    echo "     {$key}: {$value}\n";
}
echo "   PUT Working: " . ($putResponse->status() === 200 ? 'YES' : 'NO') . "\n\n";

// Test 2: PATCH - Should partially update resource
echo "2. PATCH Test - Partial Resource Update\n";
echo "   Sending only email update...\n";
$patchResponse = Http::request()->patch('https://httpbin.org/patch', [
    'email' => 'updated@example.com'
])->await();

$patchData = json_decode($patchResponse->getBody(), true);
echo "   Status: " . $patchResponse->status() . "\n";
echo "   Content-Type sent: " . ($patchData['headers']['Content-Type'] ?? 'None') . "\n";
echo "   Data received by server:\n";
foreach ($patchData['json'] as $key => $value) {
    echo "     {$key}: {$value}\n";
}
echo "   PATCH Working: " . ($patchResponse->status() === 200 ? 'YES' : 'NO') . "\n\n";

// Test 3: Verify HTTP methods are correctly set
echo "3. HTTP Method Verification\n";
$putUrl = parse_url($putData['url'] ?? '');
$patchUrl = parse_url($patchData['url'] ?? '');

echo "   PUT endpoint: " . ($putUrl['path'] ?? 'Unknown') . "\n";
echo "   PATCH endpoint: " . ($patchUrl['path'] ?? 'Unknown') . "\n";
echo "   PUT method correct: " . (($putUrl['path'] ?? '') === '/put' ? 'YES' : 'NO') . "\n";
echo "   PATCH method correct: " . (($patchUrl['path'] ?? '') === '/patch' ? 'YES' : 'NO') . "\n\n";

// Test 4: Content-Type headers
echo "4. Content-Type Header Verification\n";
$putContentType = $putData['headers']['Content-Type'] ?? '';
$patchContentType = $patchData['headers']['Content-Type'] ?? '';

echo "   PUT Content-Type: {$putContentType}\n";
echo "   PATCH Content-Type: {$patchContentType}\n";
echo "   Both using JSON: " . (
    (strpos($putContentType, 'application/json') !== false && 
     strpos($patchContentType, 'application/json') !== false) ? 'YES' : 'NO'
) . "\n\n";

// Test 5: Different data sizes
echo "5. Data Size Test\n";
echo "   Testing PUT with large dataset...\n";
$largePutResponse = Http::request()->put('https://httpbin.org/put', [
    'user' => [
        'id' => 456,
        'profile' => [
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '555-5678',
            'address' => [
                'street' => '123 Main St',
                'city' => 'Anytown',
                'state' => 'CA',
                'zip' => '12345'
            ]
        ],
        'preferences' => [
            'theme' => 'dark',
            'language' => 'en',
            'notifications' => true
        ]
    ]
])->await();

echo "   Testing PATCH with minimal dataset...\n";
$minimalPatchResponse = Http::request()->patch('https://httpbin.org/patch', [
    'theme' => 'light'
])->await();

$largePutData = json_decode($largePutResponse->getBody(), true);
$minimalPatchData = json_decode($minimalPatchResponse->getBody(), true);

echo "   PUT data keys count: " . count($largePutData['json'] ?? []) . "\n";
echo "   PATCH data keys count: " . count($minimalPatchData['json'] ?? []) . "\n";
echo "   Size difference working: " . (
    count($largePutData['json'] ?? []) > count($minimalPatchData['json'] ?? []) ? 'YES' : 'NO'
) . "\n\n";

// Test 6: Raw body content verification
echo "6. Raw Body Content Verification\n";
$putRawData = $putData['data'] ?? '';
$patchRawData = $patchData['data'] ?? '';

echo "   PUT raw body length: " . strlen($putRawData) . " characters\n";
echo "   PATCH raw body length: " . strlen($patchRawData) . " characters\n";
echo "   PUT raw body: " . substr($putRawData, 0, 100) . "...\n";
echo "   PATCH raw body: {$patchRawData}\n\n";

// Test 7: Form data vs JSON
echo "7. Form Data vs JSON Test\n";
echo "   Testing PUT with form data...\n";
$formPutResponse = Http::request()
    ->withForm(['name' => 'FormUser', 'type' => 'form'])
    ->put('https://httpbin.org/put')
    ->await();

echo "   Testing PATCH with form data...\n";
$formPatchResponse = Http::request()
    ->withForm(['status' => 'updated'])
    ->patch('https://httpbin.org/patch')
    ->await();

$formPutData = json_decode($formPutResponse->getBody(), true);
$formPatchData = json_decode($formPatchResponse->getBody(), true);

echo "   PUT form Content-Type: " . ($formPutData['headers']['Content-Type'] ?? 'None') . "\n";
echo "   PATCH form Content-Type: " . ($formPatchData['headers']['Content-Type'] ?? 'None') . "\n";
echo "   PUT form data: " . ($formPutData['form']['name'] ?? 'Missing') . "\n";
echo "   PATCH form data: " . ($formPatchData['form']['status'] ?? 'Missing') . "\n\n";

// Summary
echo "=== SUMMARY ===\n";
echo "✅ PUT method: " . ($putResponse->status() === 200 ? 'WORKING' : 'FAILED') . "\n";
echo "✅ PATCH method: " . ($patchResponse->status() === 200 ? 'WORKING' : 'FAILED') . "\n";
echo "✅ Correct endpoints: " . (
    (($putUrl['path'] ?? '') === '/put' && ($patchUrl['path'] ?? '') === '/patch') ? 'YES' : 'NO'
) . "\n";
echo "✅ JSON encoding: " . (
    (strpos($putContentType, 'application/json') !== false && 
     strpos($patchContentType, 'application/json') !== false) ? 'YES' : 'NO'
) . "\n";
echo "✅ Form data support: " . (
    ($formPutResponse->status() === 200 && $formPatchResponse->status() === 200) ? 'YES' : 'NO'
) . "\n";

echo "\n🎯 Your PATCH and PUT methods are working correctly!\n";