<?php

require __DIR__ . '/vendor/autoload.php';

use Hibla\Http\Http;

/**
 * Race Condition Tests with Standard HTTP Headers
 * Using headers that httpbin definitely accepts
 */

echo "🧪 Race Condition Tests (Standard Headers)\n";
echo "=" . str_repeat("=", 50) . "\n\n";

$results = run(async(function() {

    $baseClient = Http::request()
        ->userAgent('RaceConditionTest/1.0')
        ->timeout(30);

    echo "📋 Base Client Created\n\n";

    // Test 5: Using Standard Headers that httpbin accepts
    echo "🔬 TEST 5: Standard Headers Test (8 Clients)\n";
    echo "-" . str_repeat("-", 40) . "\n";

    $raceClients = [];
    $racePromises = [];
    $expectedConfigs = [];
    $clientObjectHashes = [];

    for ($i = 0; $i < 8; $i++) {
        $clientId = "client{$i}";
        $timestamp = microtime(true) + ($i * 0.001);
        
        $raceClients[$i] = $baseClient
            ->timeout(10 + ($i % 3))
            ->header('Authorization', "Bearer token-{$i}")        // Standard header
            ->header('Accept', "application/json-v{$i}")          // Standard header  
            ->header('Content-Type', "application/json-{$i}")     // Standard header
            ->header('Cache-Control', "max-age=" . (3600 + $i))   // Standard header
            ->header('X-Requested-With', "XMLHttpRequest-{$i}")   // Common custom header
            ->userAgent("RaceTest/{$i}");
        
        $clientObjectHashes[$i] = spl_object_hash($raceClients[$i]);
            
        $expectedConfigs[$clientId] = [
            'authorization' => "Bearer token-{$i}",
            'accept' => "application/json-v{$i}",
            'content_type' => "application/json-{$i}",
            'cache_control' => "max-age=" . (3600 + $i),
            'x_requested_with' => "XMLHttpRequest-{$i}",
            'user_agent' => "RaceTest/{$i}"
        ];
            
        $racePromises[$clientId] = $raceClients[$i]->get('https://httpbin.org/headers');
    }

    // Immutability check
    echo "🔍 IMMUTABILITY CHECK:\n";
    $baseClientHash = spl_object_hash($baseClient);
    $uniqueRaceIds = array_unique($clientObjectHashes);
    
    echo "   ✓ Base client hash: " . substr($baseClientHash, -8) . "\n";
    echo "   ✓ Unique object IDs: " . count($uniqueRaceIds) . "/8\n";
    echo "   ✓ All unique: " . (count($uniqueRaceIds) === 8 ? "PASS" : "FAIL") . "\n\n";

    // Show expected configs
    echo "📸 EXPECTED CONFIGURATIONS:\n";
    for ($i = 0; $i < 3; $i++) {
        $expected = $expectedConfigs["client{$i}"];
        echo "   Client {$i}:\n";
        echo "     Authorization: {$expected['authorization']}\n";
        echo "     Accept: {$expected['accept']}\n";
        echo "     Content-Type: {$expected['content_type']}\n";
        echo "     User-Agent: {$expected['user_agent']}\n";
    }

    echo "\n🚀 Executing 8 concurrent requests...\n";
    $startTime = microtime(true);
    $results = await(all($racePromises));
    $duration = microtime(true) - $startTime;
    echo "⏱️ Completed in " . number_format($duration, 2) . "s\n\n";

    // Analyze results
    $correctResults = [];
    $fieldSuccess = [
        'authorization' => 0,
        'accept' => 0,
        'content_type' => 0,
        'cache_control' => 0,
        'x_requested_with' => 0,
        'user_agent' => 0
    ];

    foreach ($results as $clientId => $response) {
        $headers = $response->json()['headers'];
        $expected = $expectedConfigs[$clientId];
        
        // Check standard headers (httpbin normalizes header names)
        $actualAuth = $headers['Authorization'] ?? '';
        $actualAccept = $headers['Accept'] ?? '';
        $actualContentType = $headers['Content-Type'] ?? '';
        $actualCacheControl = $headers['Cache-Control'] ?? '';
        $actualRequestedWith = $headers['X-Requested-With'] ?? '';
        $actualUserAgent = $headers['User-Agent'] ?? '';
        
        $authMatch = ($actualAuth === $expected['authorization']);
        $acceptMatch = ($actualAccept === $expected['accept']);
        $contentTypeMatch = ($actualContentType === $expected['content_type']);
        $cacheControlMatch = ($actualCacheControl === $expected['cache_control']);
        $requestedWithMatch = ($actualRequestedWith === $expected['x_requested_with']);
        $userAgentMatch = ($actualUserAgent === $expected['user_agent']);
        
        // Update success counters
        if ($authMatch) $fieldSuccess['authorization']++;
        if ($acceptMatch) $fieldSuccess['accept']++;
        if ($contentTypeMatch) $fieldSuccess['content_type']++;
        if ($cacheControlMatch) $fieldSuccess['cache_control']++;
        if ($requestedWithMatch) $fieldSuccess['x_requested_with']++;
        if ($userAgentMatch) $fieldSuccess['user_agent']++;
        
        $allMatch = $authMatch && $acceptMatch && $contentTypeMatch && $cacheControlMatch && $requestedWithMatch && $userAgentMatch;
        
        if ($allMatch) {
            $correctResults[] = $clientId;
        } else {
            echo "❌ {$clientId} mismatches:\n";
            if (!$authMatch) echo "   • Authorization: expected '{$expected['authorization']}', got '{$actualAuth}'\n";
            if (!$acceptMatch) echo "   • Accept: expected '{$expected['accept']}', got '{$actualAccept}'\n";
            if (!$contentTypeMatch) echo "   • Content-Type: expected '{$expected['content_type']}', got '{$actualContentType}'\n";
            if (!$cacheControlMatch) echo "   • Cache-Control: expected '{$expected['cache_control']}', got '{$actualCacheControl}'\n";
            if (!$requestedWithMatch) echo "   • X-Requested-With: expected '{$expected['x_requested_with']}', got '{$actualRequestedWith}'\n";
            if (!$userAgentMatch) echo "   • User-Agent: expected '{$expected['user_agent']}', got '{$actualUserAgent}'\n";
        }
    }

    echo "\n📊 FIELD SUCCESS RATES:\n";
    foreach ($fieldSuccess as $field => $count) {
        $rate = round(($count / 8) * 100, 1);
        $status = $count === 8 ? "✅" : "❌";
        echo "   {$status} {$field}: {$count}/8 ({$rate}%)\n";
    }

    $successRate = round((count($correctResults) / 8) * 100, 1);
    echo "\n✅ Overall success: " . count($correctResults) . "/8 ({$successRate}%)\n";

    $test5Passed = (count($correctResults) === 8);
    
    // Test 6: Simple Accept header test
    echo "\n🔬 TEST 6: Simple Accept Header Test (5 Clients)\n";
    echo "-" . str_repeat("-", 40) . "\n";

    $simplePromises = [];
    $simpleExpected = [];
    $simpleHashes = [];

    for ($i = 0; $i < 5; $i++) {
        $acceptValue = "application/test-v{$i}";
        
        $client = $baseClient
            ->header('Accept', $acceptValue)
            ->userAgent("SimpleTest/{$i}");
        
        $simpleHashes[$i] = spl_object_hash($client);
        $simpleExpected["simple{$i}"] = [
            'accept' => $acceptValue,
            'user_agent' => "SimpleTest/{$i}"
        ];
        
        $simplePromises["simple{$i}"] = $client->get('https://httpbin.org/headers');
        
        await(delay(0.005));
    }

    echo "🔍 Simple test object uniqueness: " . count(array_unique($simpleHashes)) . "/5\n\n";

    echo "🚀 Executing 5 simple requests...\n";
    $simpleResults = await(all($simplePromises));

    $simpleCorrect = 0;
    foreach ($simpleResults as $name => $response) {
        $headers = $response->json()['headers'];
        $expected = $simpleExpected[$name];
        
        $actualAccept = $headers['Accept'] ?? '';
        $actualUserAgent = $headers['User-Agent'] ?? '';
        
        $acceptMatch = ($actualAccept === $expected['accept']);
        $userAgentMatch = ($actualUserAgent === $expected['user_agent']);
        
        if ($acceptMatch && $userAgentMatch) {
            $simpleCorrect++;
            echo "✅ {$name}: Accept='{$actualAccept}', UA='{$actualUserAgent}'\n";
        } else {
            echo "❌ {$name}: Accept expected '{$expected['accept']}', got '{$actualAccept}'\n";
            echo "         UA expected '{$expected['user_agent']}', got '{$actualUserAgent}'\n";
        }
    }

    $test6Passed = ($simpleCorrect === 5);

    // Final summary
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🏆 STANDARD HEADERS TEST RESULTS\n";
    echo str_repeat("=", 60) . "\n";
    
    $immutabilityScore = round((count($uniqueRaceIds) / 8) * 100, 1);
    echo "🔍 Object immutability: {$immutabilityScore}%\n";
    echo "📊 Configuration isolation: {$successRate}%\n\n";
    
    echo ($test5Passed ? "✅" : "❌") . " Test 5 - Standard Headers: " . ($test5Passed ? "PASSED" : "FAILED") . "\n";
    echo ($test6Passed ? "✅" : "❌") . " Test 6 - Simple Accept: " . ($test6Passed ? "PASSED" : "FAILED") . "\n";

    $totalPassed = ($test5Passed ? 1 : 0) + ($test6Passed ? 1 : 0);
    echo "\n🎯 SCORE: {$totalPassed}/2 tests passed\n";

    if ($totalPassed === 2) {
        echo "🎉 SUCCESS: No race conditions! Headers work correctly!\n";
    } else {
        echo "⚠️ Issues detected - likely HTTP client immutability problems\n";
    }

    echo "\n" . str_repeat("=", 60) . "\n";

    return [$totalPassed, $immutabilityScore, $successRate];
}));

echo "\n🏁 Final Score: {$results[0]}/2, Immutability: {$results[1]}%, Success: {$results[2]}%\n";