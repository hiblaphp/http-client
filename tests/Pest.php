<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

use Hibla\HttpClient\Testing\Utilities\CacheManager;
use Hibla\HttpClient\Testing\Utilities\CookieManager;
use Hibla\HttpClient\Testing\Utilities\FileManager;

pest()->extend(Tests\TestCase::class)->in('Feature');
pest()->extend(Tests\TestCase::class)->in('Unit');
pest()->extend(Tests\TestCase::class)->in('Integration');

function getPrivateProperty($object, string $property)
{
    $reflection = new \ReflectionClass($object);
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(true);
    return $prop->getValue($object);
}

function callPrivateMethod($object, string $method, array $args = []) {
    $reflection = new \ReflectionClass($object);
    $method = $reflection->getMethod($method);
    $method->setAccessible(true);
    return $method->invoke($object, ...$args);
}

function createCacheManager(): CacheManager
{
    return new CacheManager();
}

function createCookieManager(bool $autoManage = true): CookieManager
{
    return new CookieManager($autoManage);
}

function createFileManager(bool $autoManage = true): FileManager
{
    return new FileManager($autoManage);
}
