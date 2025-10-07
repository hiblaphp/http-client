<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hibla\HttpClient\Http;
use Hibla\HttpClient\RetryConfig;
use function Hibla\await;

echo "=== Retry-then-Cache Integration Test ===\n\n";

// 1. ARRANGE: Start testing and set up the mock sequence.
Http::startTesting();
Http::getTestingHandler()->reset(); // Ensure a clean slate

// Use the `failUntilAttempt` helper to create a sequence where:
// - Attempt 1 fails.
// - Attempt 2 fails.
// - Attempt 3 succeeds with a 1.5-second delay.
Http::mock()
    ->url('https://api.example.com/flaky-service')
    ->delay(1.5) // Add delay only to the successful response
    ->failUntilAttempt(3)
    ->respondJson(['uuid' => 'success-after-retries'])
    ->register();

// Configure retry logic to try up to 3 times with a short delay for fast testing.
$retryConfig = new RetryConfig(
    maxRetries: 3,
    baseDelay: 0.1,
    jitter: false
);

// --- EXECUTION ---

// Request 1: Should experience failures, retries, and a final delay.
echo "Request 1 (cache miss, should trigger retries):\n";
$start1 = microtime(true);
$response1 = await(
    Http::request()
        ->cache(60) // Caching is enabled
        ->retryWith($retryConfig)
        ->get('https://api.example.com/flaky-service')
);
$duration1 = microtime(true) - $start1;

$uuid1 = $response1->ok() ? json_decode($response1->body(), true)['uuid'] : 'N/A';
echo "  UUID: $uuid1\n";
echo "  Duration: " . round($duration1, 2) . "s\n";
echo "  Status: {$response1->status()}\n";
Http::assertRequestCount(5); // VERIFY that 3 requests were made.
echo "  (Verified that 3 requests were made for the first call)\n\n";

// Request 2: Should be an instant cache hit. No retries, no delay.
echo "Request 2 (should be an instant cache hit):\n";
$start2 = microtime(true);
$response2 = await(
    Http::request()
        ->cache(60)
        ->retryWith($retryConfig) // Retry logic is present but should not be needed
        ->get('https://api.example.com/flaky-service')
);
$duration2 = microtime(true) - $start2;

$uuid2 = $response2->ok() ? json_decode($response2->body(), true)['uuid'] : 'N/A';
echo "  UUID: $uuid2\n";
echo "  Duration: " . round($duration2, 3) . "s\n";
echo "  Status: {$response2->status()}\n\n";

// 3. CLEANUP
Http::stopTesting();

// --- VERIFICATION ---
echo "=== Verification ===\n";

// The total time for Request 1 should be roughly:
// (0.1s retry delay) + (0.2s retry delay) + (1.5s mock delay) = ~1.8s
echo "✅ Request 1 was slow (retries + delay): " . ($duration1 > 1.7 ? "PASS" : "FAIL") . "\n";
echo "✅ Request 2 was instant (cache): " . ($duration2 < 0.1 ? "PASS" : "FAIL") . "\n";
echo "✅ Same UUID (cached correctly): " . ($uuid1 === $uuid2 && $uuid1 !== 'N/A' ? "PASS" : "FAIL") . "\n";
echo "✅ Both returned 200 status: " .
    ($response1->status() === 200 && $response2->status() === 200 ? "PASS" : "FAIL") . "\n";

echo "\n" . ($duration1 > 1.7 && $duration2 < 0.1 && $uuid1 === $uuid2
    ? "✅ Retry-then-Cache integration test passed!"
    : "❌ Something is wrong with the integration!") . "\n";