<?php

use Hibla\Http\Http;

require __DIR__ . '/vendor/autoload.php';

echo "🍪 Debugging Cookie Jar Issue\n\n";

try {
    $client = Http::request()->withCookieJar();
    
    echo "Step 1: Making request to set cookies\n";
    $response1 = $client
        ->get('https://httpbin.org/cookies/set/test_cookie/test_value')
        ->await();
    
    echo "Response 1 Status: " . $response1->status() . "\n";
    
    // Check if cookies were received in the response
    $setCookieHeaders = $response1->getHeader('Set-Cookie');
    echo "Set-Cookie headers received: " . count($setCookieHeaders) . "\n";
    foreach ($setCookieHeaders as $cookie) {
        echo "  - $cookie\n";
    }
    
    // Check the cookie jar
    $cookieJar = $client->getCookieJar();
    if ($cookieJar) {
        $cookieHeader = $cookieJar->getCookieHeader('httpbin.org', '/', true);
        echo "Cookie jar contents: '$cookieHeader'\n";
    }
    
    echo "\nStep 2: Making second request (cookies should be sent)\n";
    $response2 = $client->get('https://httpbin.org/cookies')->await();
    
    echo "Response 2 Status: " . $response2->status() . "\n";
    echo "Response 2 Body: " . substr($response2->body(), 0, 200) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}