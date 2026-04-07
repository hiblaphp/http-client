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

function getHttpBinXmlData($response) {
    $data = $response->json('data');
    
    if (is_string($data) && str_starts_with($data, 'data:')) {
        $b64 = substr($data, strpos($data, ',') + 1);
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $b64));
    }

    return $data;
}