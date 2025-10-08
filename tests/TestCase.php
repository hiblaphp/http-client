<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public $cacheManager;

    protected function setUp(): void
    {
        parent::setUp();
    }
}
