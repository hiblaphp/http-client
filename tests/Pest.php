<?php

declare(strict_types=1);

function getPrivateProperty($object, string $property)
{
    $reflection = new ReflectionClass($object);
    $prop = $reflection->getProperty($property);

    return $prop->getValue($object);
}

