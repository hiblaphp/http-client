<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Tests\Fixtures\HttpBin;

use function Hibla\await;

describe('XML Request and Response Handling', function () {
    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    describe('parsing the /xml endpoint response', function () {
        it('returns a 200 with an application/xml content-type header', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            expect($response->successful())->toBeTrue();
            expect($response->header('content-type'))->toContain('application/xml');
        });

        it('parses the /xml response into a SimpleXMLElement', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            expect($response->xml())->toBeInstanceOf(SimpleXMLElement::class);
        });

        it('has a root element named slideshow', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            expect($response->xml()->getName())->toBe('slideshow');
        });

        it('carries the expected slideshow title attribute', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            $xml = $response->xml();

            expect((string) $xml['title'])->toBe('Sample Slide Show');
        });

        it('carries the expected author attribute', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            expect((string) $response->xml()['author'])->toBe('Yours Truly');
        });

        it('contains exactly two slide children', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            expect(count($response->xml()->slide))->toBe(2);
        });

        it('has the correct title on the first slide', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            $firstTitle = (string) $response->xml()->slide[0]->title;

            expect($firstTitle)->toBe('Wake up to WonderWidgets!');
        });

        it('has the correct title on the second slide', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            $secondTitle = (string) $response->xml()->slide[1]->title;

            expect($secondTitle)->toBe('Overview');
        });

        it('first slide has type attribute set to all', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            expect((string) $response->xml()->slide[0]['type'])->toBe('all');
        });

        it('second slide has type attribute set to all', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            expect((string) $response->xml()->slide[1]['type'])->toBe('all');
        });

        it('second slide contains item children', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            expect(count($response->xml()->slide[1]->item))->toBeGreaterThan(0);
        });

        it('xml() is idempotent — calling it twice returns equivalent trees', function () {
            $response = await(Http::request()->get(HttpBin::url('/xml')));

            $first = $response->xml();
            $second = $response->xml();

            expect($first->getName())->toBe($second->getName());
            expect((string) $first['title'])->toBe((string) $second['title']);
        });
    });

    describe('sending XML bodies (echo via /post)', function () {
        it('sends a raw XML string body with the correct content-type', function () {
            $xml = '<order><id>42</id><item>Widget</item></order>';

            $response = await(
                Http::request()
                    ->asXml()
                    ->body($xml)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Content-Type.0'))->toContain('application/xml');
            expect(getHttpBinXmlData($response))->toBe($xml);
        });

        it('sends a SimpleXMLElement body', function () {
            $xml = new SimpleXMLElement('<product><name>Hibla</name><version>1.0</version></product>');

            $response = await(
                Http::request()
                    ->withXml($xml)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect(getHttpBinXmlData($response))->toContain('<name>Hibla</name>');
        });

        it('sends an XML string via withXml()', function () {
            $xml = '<ping><status>ok</status></ping>';

            $response = await(
                Http::request()
                    ->withXml($xml)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect(getHttpBinXmlData($response))->toContain('<status>ok</status>');
        });

        it('sends XML with nested elements', function () {
            $xml = <<<XML
            <catalog>
                <book id="1"><title>Clean Code</title></book>
                <book id="2"><title>The Pragmatic Programmer</title></book>
            </catalog>
            XML;

            $response = await(
                Http::request()
                    ->withXml($xml)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect(getHttpBinXmlData($response))->toContain('<title>Clean Code</title>');
            expect(getHttpBinXmlData($response))->toContain('<title>The Pragmatic Programmer</title>');
        });

        it('sends XML with unicode content', function () {
            $xml = '<message><text>こんにちは — héllo — 你好</text></message>';

            $response = await(
                Http::request()
                    ->withXml($xml)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect(getHttpBinXmlData($response))->toContain('こんにちは');
        });

        it('sends XML with bearer token auth', function () {
            $response = await(
                Http::request()
                    ->withXml('<secure><payload>secret</payload></secure>')
                    ->withToken('xml-bearer-token')
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toBe('Bearer xml-bearer-token');
        });

        it('sends XML with basic auth', function () {
            $response = await(
                Http::request()
                    ->withXml('<request><action>login</action></request>')
                    ->withBasicAuth('user', 'pass')
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toStartWith('Basic ');
        });

        it('sends XML with a custom header', function () {
            $response = await(
                Http::request()
                    ->withXml('<event><type>click</type></event>')
                    ->withHeader('X-Source', 'hibla-xml-test')
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.X-Source.0'))->toBe('hibla-xml-test');
        });
    });

    describe('content negotiation', function () {
        it('asXml() sets Content-Type without overriding an explicit Accept', function () {
            $response = await(
                Http::request()
                    ->asXml()
                    ->accept('application/json')
                    ->body('<probe/>')
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Content-Type.0'))->toContain('application/xml');
            expect($response->json('headers.Accept.0'))->toContain('application/json');
        });
    });

    describe('xml() edge cases', function () {
        it('returns null for an empty response body', function () {
            $response = await(Http::request()->get(HttpBin::url('/status/204')));

            expect($response->xml())->toBeNull();
        });

        it('returns null for a non-XML body', function () {
            $response = await(Http::request()->get(HttpBin::url('/get')));

            expect($response->xml())->toBeNull();
        });
    });

    describe('error conditions', function () {
        it('returns 422 and failed() for a bad endpoint with an XML body', function () {
            $response = await(
                Http::request()
                    ->withXml('<bad/>')
                    ->post(HttpBin::url('/status/422'))
            );

            expect($response->status())->toBe(422);
            expect($response->successful())->toBeFalse();
            expect($response->clientError())->toBeTrue();
        });

        it('returns 500 and serverError() for an error endpoint with an XML body', function () {
            $response = await(
                Http::request()
                    ->withXml('<trigger><error>true</error></trigger>')
                    ->post(HttpBin::url('/status/500'))
            );

            expect($response->status())->toBe(500);
            expect($response->serverError())->toBeTrue();
        });
    });
});
