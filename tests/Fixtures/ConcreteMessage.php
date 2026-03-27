<?php

namespace Tests\Fixtures;

use Hibla\HttpClient\Message;
use Hibla\HttpClient\Stream;

class ConcreteMessage extends Message
{
    public function __construct(array $headers = [])
    {
        $this->body = new Stream(fopen('php://memory', 'r+'));
        $this->setHeaders($headers);
    }
}