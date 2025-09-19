<?php
require __DIR__ . '/vendor/autoload.php';

use Hibla\Http\Http;
use Hibla\Promise\Promise;

echo "🔍 DEMONSTRATING THE RACE CONDITION\n";
echo "===================================\n\n";

echo "1. Testing object identity:\n";
echo "---------------------------\n";
run(function () {
    $base = Http::request();
    echo "Base object ID: " . spl_object_id($base) . "\n";
    
    $client1 = $base->header("CLIENT-ID", "A");
    echo "Client1 object ID: " . spl_object_id($client1) . "\n";
    echo "Client1 === Base? " . ($client1 === $base ? "YES" : "NO") . "\n\n";
    
    $client2 = $base->header("CLIENT-ID", "B"); 
    echo "Client2 object ID: " . spl_object_id($client2) . "\n";
    echo "Client2 === Base? " . ($client2 === $base ? "YES" : "NO") . "\n";
    echo "Client2 === Client1? " . ($client2 === $client1 ? "YES" : "NO") . "\n\n";
});

echo "2. Working pattern (immediate execution):\n";
echo "-----------------------------------------\n";
$workingResults = run(function () {
    $base = Http::request();
    $url = "https://httpbin.org/headers";
    
    echo "Executing with immediate get() calls...\n";
    
    return await(Promise::all([
        "client1" => $base->header("CLIENT-ID", "1")->get($url),
        "client2" => $base->header("CLIENT-ID", "2")->get($url),  
        "client3" => $base->header("CLIENT-ID", "3")->get($url),
    ]));
});

foreach ($workingResults as $key => $response) {
    $data = $response->json();
    $clientId = $data['headers']['Client-Id'] ?? 'MISSING';
    echo "$key: CLIENT-ID = $clientId\n";
}

echo "\n3. Broken pattern (delayed execution):\n";
echo "--------------------------------------\n";
$brokenResults = run(function () {
    $base = Http::request();
    $url = "https://httpbin.org/headers";
    
    echo "Building clients first, then executing...\n";
    
    // Build clients (all modify same $base)
    $client1 = $base->header("CLIENT-ID", "X");
    echo "After setting X - Object ID: " . spl_object_id($client1) . "\n";
    
    $client2 = $base->header("CLIENT-ID", "Y");
    echo "After setting Y - Object ID: " . spl_object_id($client2) . "\n";
    
    $client3 = $base->header("CLIENT-ID", "Z");
    echo "After setting Z - Object ID: " . spl_object_id($client3) . "\n";
    
    echo "All variables point to same object: " . 
         ($client1 === $client2 && $client2 === $client3 ? "YES" : "NO") . "\n\n";
    
    // Now execute (all use final state)
    return await(Promise::all([
        "clientX" => $client1->get($url),
        "clientY" => $client2->get($url),
        "clientZ" => $client3->get($url),
    ]));
});

foreach ($brokenResults as $key => $response) {
    $data = $response->json();
    $clientId = $data['headers']['Client-Id'] ?? 'MISSING';
    echo "$key: CLIENT-ID = $clientId\n";
}

echo "\n🎯 CONCLUSION:\n";
echo "==============\n";
echo "✅ Your original pattern works because of immediate execution timing\n";
echo "❌ The broken pattern fails because all variables reference the same mutable object\n";
echo "💡 For guaranteed safety, always use isolated instances:\n";
echo "   Http::request()->header()->get() (create new instance each time)\n";