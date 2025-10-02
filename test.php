<?php

use Hibla\Http\Http;
use Hibla\EventLoop\EventLoop;

require 'vendor/autoload.php';

Http::startTesting()->withGlobalRandomDelay(1, 1);

Http::mock("GET")->url("https://example.com")
    ->respondJson(["message" => "Hello, World!"])
    ->failUntilAttempt(3)
    ->register();

echo "\n=== Before HTTP request ===\n";
$loop = EventLoop::getInstance();
echo "Has timers: " . ($loop->hasTimers() ? 'YES' : 'NO') . "\n";
echo "Is running: " . ($loop->isRunning() ? 'YES' : 'NO') . "\n";

$promise = Http::retry(maxRetries: 2, baseDelay: 1, backoffMultiplier: 2)
    ->get("https://example.com")
    ->then(function ($response) {
        echo "\n=== SUCCESS ===\n";
        echo $response->getBody() . PHP_EOL;
    })
    ->catch(function (Exception $error) {
        echo "\n=== ERROR ===\n";
        echo "Error: " . $error->getMessage() . PHP_EOL;
    });

echo "\n=== After HTTP request, before run ===\n";
echo "Has timers: " . ($loop->hasTimers() ? 'YES' : 'NO') . "\n";
echo "Timer count: " . count($loop->getTimerManager()->getTimerStats()['total_timers'] ?? 0) . "\n";

echo "\n=== Starting event loop ===\n";

// Try manual processing
$maxTicks = 100;
$tickCount = 0;

while ($loop->hasTimers() && $tickCount < $maxTicks) {
    echo "Tick #{$tickCount} - Has timers: " . ($loop->hasTimers() ? 'YES' : 'NO') . "\n";
    
    // Process timers manually
    $loop->getTimerManager()->processTimers();
    
    $tickCount++;
    
    // Small delay to allow timers to become ready
    usleep(100000); // 100ms
}

echo "\n=== Event loop finished ===\n";
echo "Total ticks: {$tickCount}\n";
echo "Has timers: " . ($loop->hasTimers() ? 'YES' : 'NO') . "\n";