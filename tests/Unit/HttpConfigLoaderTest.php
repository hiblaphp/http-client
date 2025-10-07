<?php

use Hibla\HttpClient\Config\HttpConfigLoader;

beforeEach(function () {
    HttpConfigLoader::reset();
});

test('it is a singleton and returns the same instance', function () {
    $instance1 = HttpConfigLoader::getInstance();
    $instance2 = HttpConfigLoader::getInstance();

    expect($instance1)->toBeInstanceOf(HttpConfigLoader::class);
    expect($instance1 === $instance2)->toBeTrue();
});

test('it can find the project root directory', function () {
    $loader = HttpConfigLoader::getInstance();
    $rootPath = $loader->getRootPath();

    expect($rootPath)->not->toBeNull();
    expect(is_dir($rootPath . '/vendor'))->toBeTrue();
});

test('it loads configuration files from the config directory', function () {
    $loader = HttpConfigLoader::getInstance();
    $root = $loader->getRootPath();

    $configDir = $root . '/config/http';
    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }
    
    $clientConfigPath = $configDir . '/client.php';
    file_put_contents($clientConfigPath, "<?php return ['timeout' => 99];");

    HttpConfigLoader::reset();
    $newLoader = HttpConfigLoader::getInstance();
    $config = $newLoader->get('client');

    expect($config)->toBe(['timeout' => 99]);

    unlink($clientConfigPath);
    rmdir($configDir);
});