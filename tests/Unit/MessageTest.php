<?php

use Hibla\HttpClient\Message;
use Hibla\HttpClient\Stream;
use Psr\Http\Message\StreamInterface;

class ConcreteMessage extends Message
{
    public function __construct(array $headers = [])
    {
        $this->body = new Stream(fopen('php://memory', 'r+'));
        $this->setHeaders($headers);
    }
}

describe('Protocol Version', function () {
    it('defaults to protocol version 1.1', function () {
        $message = new ConcreteMessage();
        expect($message->getProtocolVersion())->toBe('1.1');
    });

    it('is immutable when changing protocol version', function () {
        $message1 = new ConcreteMessage();
        $message2 = $message1->withProtocolVersion('2.0');

        expect($message1)->not->toBe($message2);
        expect($message1->getProtocolVersion())->toBe('1.1');
        expect($message2->getProtocolVersion())->toBe('2.0');
    });
});

describe('Body', function () {
    it('getBody returns the stream instance', function () {
        $message = new ConcreteMessage();
        expect($message->getBody())->toBeInstanceOf(StreamInterface::class);
    });

    it('is immutable when changing the body', function () {
        $stream1 = new Stream(fopen('php://memory', 'r+'));
        $stream2 = new Stream(fopen('php://memory', 'r+'));

        $message1 = new ConcreteMessage();
        $message1 = $message1->withBody($stream1);
        $message2 = $message1->withBody($stream2);

        expect($message1)->not->toBe($message2);
        expect($message1->getBody())->toBe($stream1);
        expect($message2->getBody())->toBe($stream2);
    });
});

describe('Headers', function () {
    it('getHeaders returns an empty array by default', function () {
        $message = new ConcreteMessage();
        expect($message->getHeaders())->toBe([]);
    });

    it('getHeaders returns all registered headers', function () {
        $message = new ConcreteMessage(['X-Foo' => 'Bar', 'Accept' => ['application/json', 'text/plain']]);
        $expected = [
            'X-Foo' => ['Bar'],
            'Accept' => ['application/json', 'text/plain'],
        ];
        expect($message->getHeaders())->toBe($expected);
    });

    it('is case-insensitive when checking for a header', function () {
        $message = new ConcreteMessage(['Content-Type' => 'application/json']);
        expect($message->hasHeader('Content-Type'))->toBeTrue();
        expect($message->hasHeader('content-type'))->toBeTrue();
        expect($message->hasHeader('CONTENT-TYPE'))->toBeTrue();
    });

    it('getHeader is case-insensitive and always returns an array', function () {
        $message = new ConcreteMessage(['Content-Type' => 'application/json']);
        expect($message->getHeader('content-type'))->toBe(['application/json']);
    });

    it('getHeaderLine is case-insensitive and joins values with a comma', function () {
        $message = new ConcreteMessage(['X-Values' => ['foo', 'bar']]);
        expect($message->getHeaderLine('x-values'))->toBe('foo, bar');
    });

    it('withHeader is immutable and replaces existing headers', function () {
        $message1 = new ConcreteMessage(['X-Foo' => 'Bar']);
        $message2 = $message1->withHeader('x-foo', 'Baz');

        expect($message1)->not->toBe($message2);
        expect($message1->getHeaderLine('X-Foo'))->toBe('Bar');
        expect($message2->getHeaderLine('X-Foo'))->toBe('Baz');
    });

    it('withAddedHeader is immutable and appends to existing headers', function () {
        $message1 = new ConcreteMessage(['X-Foo' => 'Bar']);
        $message2 = $message1->withAddedHeader('x-foo', 'Baz');

        expect($message1)->not->toBe($message2);
        expect($message1->getHeader('X-Foo'))->toBe(['Bar']);
        expect($message2->getHeader('X-Foo'))->toBe(['Bar', 'Baz']);
    });

    it('withAddedHeader adds a new header if it does not exist', function () {
        $message1 = new ConcreteMessage();
        $message2 = $message1->withAddedHeader('X-New', 'Value');

        expect($message1->hasHeader('X-New'))->toBeFalse();
        expect($message2->hasHeader('X-New'))->toBeTrue();
        expect($message2->getHeaderLine('X-New'))->toBe('Value');
    });

    it('withoutHeader is immutable and case-insensitive', function () {
        $message1 = new ConcreteMessage(['Content-Type' => 'application/json']);
        $message2 = $message1->withoutHeader('content-type');

        expect($message1)->not->toBe($message2);
        expect($message1->hasHeader('Content-Type'))->toBeTrue();
        expect($message2->hasHeader('Content-Type'))->toBeFalse();
    });

    it('preserves header name casing', function () {
        $message = new ConcreteMessage(['X-CUSTOM-HeAdEr' => 'Value']);
        $headers = $message->getHeaders();

        expect(array_key_exists('X-CUSTOM-HeAdEr', $headers))->toBeTrue();
    });
});
