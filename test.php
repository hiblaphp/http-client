<?php

use Hibla\Http\Http;

require __DIR__ . '/vendor/autoload.php';

echo "=== Testing Raw cURL Options for Debugging ===\n\n";

// Test 1: Basic verbose debugging
echo "1. Testing CURLOPT_VERBOSE for basic debugging:\n";
try {
    // Create a temp file for debug output
    $debugFile = tmpfile();
    
    $response = Http::request()
        ->withCurlOptions([
            CURLOPT_VERBOSE => true,
            CURLOPT_STDERR => $debugFile,
            CURLOPT_USERAGENT => 'DebugTest/1.0'
        ])
        ->get("https://httpbin.org/get")
        ->await();

    // Read the debug output
    rewind($debugFile);
    $debugOutput = stream_get_contents($debugFile);
    fclose($debugFile);

    echo "   Status: " . $response->getStatusCode() . "\n";
    echo "   Debug Output Length: " . strlen($debugOutput) . " bytes\n";
    echo "   Debug Output Preview:\n";
    echo "   " . str_repeat("-", 50) . "\n";
    
    // Show first 10 lines of debug output
    $lines = explode("\n", $debugOutput);
    foreach (array_slice($lines, 0, 10) as $line) {
        echo "   " . $line . "\n";
    }
    echo "   " . str_repeat("-", 50) . "\n";
    
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: Progress tracking for large requests
echo "2. Testing CURLOPT_PROGRESSFUNCTION for download progress:\n";
try {
    $totalBytes = 0;
    $progressCalls = 0;
    
    $response = Http::request()
        ->withCurlOptions([
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function($resource, $download_size, $downloaded, $upload_size, $uploaded) use (&$totalBytes, &$progressCalls) {
                $progressCalls++;
                $totalBytes = $download_size;
                
                if ($download_size > 0) {
                    $percent = round(($downloaded / $download_size) * 100, 2);
                    // Only show every 10th progress update to avoid spam
                    if ($progressCalls % 10 === 0) {
                        echo "   Progress: {$percent}% ({$downloaded}/{$download_size} bytes)\n";
                    }
                }
                return 0; // Continue download
            },
            CURLOPT_USERAGENT => 'ProgressTest/1.0'
        ])
        ->get("https://httpbin.org/bytes/50000") // Download 50KB
        ->await();

    echo "   Final Status: " . $response->getStatusCode() . "\n";
    echo "   Total Progress Callbacks: {$progressCalls}\n";
    echo "   Final Size: " . strlen($response->getBody()) . " bytes\n";
    
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Timing and performance debugging
echo "3. Testing timing options for performance debugging:\n";
try {
    $debugFile = tmpfile();
    
    $response = Http::request()
        ->withCurlOptions([
            CURLOPT_VERBOSE => true,
            CURLOPT_STDERR => $debugFile,
            CURLOPT_CERTINFO => true, // Get certificate info
            CURLOPT_FILETIME => true, // Get file modification time
            CURLOPT_USERAGENT => 'TimingTest/1.0'
        ])
        ->get("https://httpbin.org/delay/2") // 2 second delay
        ->await();

    // Read debug output
    rewind($debugFile);
    $debugOutput = stream_get_contents($debugFile);
    fclose($debugFile);

    echo "   Status: " . $response->getStatusCode() . "\n";
    echo "   Response Size: " . strlen($response->getBody()) . " bytes\n";
    
    // Extract timing information from debug output
    $timingLines = array_filter(explode("\n", $debugOutput), function($line) {
        return strpos($line, 'Connection') !== false || 
               strpos($line, 'SSL') !== false || 
               strpos($line, 'Connected') !== false ||
               strpos($line, 'TLS') !== false;
    });
    
    echo "   Connection Details:\n";
    foreach (array_slice($timingLines, 0, 5) as $line) {
        echo "   " . trim($line) . "\n";
    }
    
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 4: Header debugging
echo "4. Testing header debugging with custom options:\n";
try {
    $debugFile = tmpfile();
    
    $response = Http::request()
        ->withHeader('X-Debug-Test', 'CustomValue')
        ->withCurlOptions([
            CURLOPT_VERBOSE => true,
            CURLOPT_STDERR => $debugFile,
            CURLOPT_HEADER => true, // Include headers in output
            CURLOPT_USERAGENT => 'HeaderDebug/1.0'
        ])
        ->get("https://httpbin.org/headers")
        ->await();

    // Read debug output
    rewind($debugFile);
    $debugOutput = stream_get_contents($debugFile);
    fclose($debugFile);

    echo "   Status: " . $response->getStatusCode() . "\n";
    
    // Extract request headers from debug output
    $headerLines = array_filter(explode("\n", $debugOutput), function($line) {
        return strpos($line, '> ') === 0; // Lines starting with > are sent headers
    });
    
    echo "   Sent Headers:\n";
    foreach (array_slice($headerLines, 0, 8) as $line) {
        echo "   " . $line . "\n";
    }
    
    // Show response
    $responseData = json_decode($response->getBody(), true);
    $receivedHeaders = $responseData['headers'] ?? [];
    echo "   Received Custom Header: " . ($receivedHeaders['X-Debug-Test'] ?? 'Not found') . "\n";
    
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 5: SSL/TLS debugging
echo "5. Testing SSL/TLS debugging:\n";
try {
    $debugFile = tmpfile();
    
    $response = Http::request()
        ->withCurlOptions([
            CURLOPT_VERBOSE => true,
            CURLOPT_STDERR => $debugFile,
            CURLOPT_CERTINFO => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'SSLDebug/1.0'
        ])
        ->get("https://httpbin.org/get")
        ->await();

    // Read debug output
    rewind($debugFile);
    $debugOutput = stream_get_contents($debugFile);
    fclose($debugFile);

    echo "   Status: " . $response->getStatusCode() . "\n";
    
    // Extract SSL information
    $sslLines = array_filter(explode("\n", $debugOutput), function($line) {
        return stripos($line, 'ssl') !== false || 
               stripos($line, 'tls') !== false || 
               stripos($line, 'certificate') !== false ||
               stripos($line, 'cipher') !== false;
    });
    
    echo "   SSL/TLS Information:\n";
    foreach (array_slice($sslLines, 0, 5) as $line) {
        echo "   " . trim($line) . "\n";
    }
    
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 6: Network interface debugging
echo "6. Testing network and DNS debugging:\n";
try {
    $debugFile = tmpfile();
    
    $response = Http::request()
        ->withCurlOptions([
            CURLOPT_VERBOSE => true,
            CURLOPT_STDERR => $debugFile,
            CURLOPT_DNS_CACHE_TIMEOUT => 0, // Disable DNS cache for fresh lookup
            CURLOPT_FRESH_CONNECT => true,  // Force new connection
            CURLOPT_USERAGENT => 'NetworkDebug/1.0'
        ])
        ->get("https://httpbin.org/ip")
        ->await();

    // Read debug output
    rewind($debugFile);
    $debugOutput = stream_get_contents($debugFile);
    fclose($debugFile);

    echo "   Status: " . $response->getStatusCode() . "\n";
    
    // Extract DNS and connection info
    $networkLines = array_filter(explode("\n", $debugOutput), function($line) {
        return stripos($line, 'trying') !== false || 
               stripos($line, 'connected') !== false || 
               stripos($line, 'host') !== false ||
               stripos($line, 'resolve') !== false;
    });
    
    echo "   Network Information:\n";
    foreach (array_slice($networkLines, 0, 5) as $line) {
        echo "   " . trim($line) . "\n";
    }
    
    // Show our IP
    $responseData = json_decode($response->getBody(), true);
    echo "   Our IP: " . ($responseData['origin'] ?? 'Not found') . "\n";
    
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

echo "\n=== Debugging Tests Complete ===\n";