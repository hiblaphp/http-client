<?php

declare(strict_types=1);

use Hibla\HttpClient\Testing\TestingHttpHandler;

function getPrivateProperty($object, string $property)
{
    $reflection = new ReflectionClass($object);
    $prop = $reflection->getProperty($property);

    return $prop->getValue($object);
}

function testingHttpHandler(): TestingHttpHandler
{
    return new TestingHttpHandler();
}