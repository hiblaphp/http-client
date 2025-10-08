<?php

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Stream;

test('parses multiple SSE events from chunk', function () {
    $sseData = <<<SSE
id: 1
event: message
data: First event

id: 2
event: update
data: Second event
data: with multiple lines

: this is a comment

id: 3
data: Third event


SSE;

    $stream = new Stream(fopen('php://temp', 'r+'));
    $response = new SSEResponse($stream);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(3);

    expect($events[0]->id)->toBe('1');
    expect($events[0]->event)->toBe('message');
    expect($events[0]->data)->toBe('First event');

    expect($events[1]->id)->toBe('2');
    expect($events[1]->event)->toBe('update');
    expect($events[1]->data)->toBe("Second event\nwith multiple lines");

    expect($events[2]->id)->toBe('3');
    expect($events[2]->data)->toBe('Third event');
});

test('handles incomplete SSE chunks', function () {
    $stream = new Stream(fopen('php://temp', 'r+'));
    $response = new SSEResponse($stream);

    $events1 = iterator_to_array($response->parseEvents("id: 1\ndata: Part"));
    expect($events1)->toHaveCount(0);

    $events2 = iterator_to_array($response->parseEvents("ial data\n\n"));
    expect($events2)->toHaveCount(1);
    expect($events2[0]->data)->toBe('Partial data');
});

test('extracts last event ID', function () {
    $stream = new Stream(fopen('php://temp', 'r+'));
    $response = new SSEResponse($stream);

    $sseData = "id: event-123\ndata: test\n\n";
    iterator_to_array($response->parseEvents($sseData));

    expect($response->getLastEventId())->toBe('event-123');
});

test('parses retry interval', function () {
    $stream = new Stream(fopen('php://temp', 'r+'));
    $response = new SSEResponse($stream);

    $sseData = "retry: 5000\n\n";
    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(1);
    expect($events[0]->retry)->toBe(5000);
});

test('ignores comments', function () {
    $stream = new Stream(fopen('php://temp', 'r+'));
    $response = new SSEResponse($stream);

    $sseData = ": this is a comment\ndata: real data\n\n";
    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(1);
    expect($events[0]->data)->toBe('real data');
});


it('parses single SSE event', function () {
    $sseData = "data: Hello World\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(SSEEvent::class)
        ->and($events[0]->data)->toBe('Hello World');
});

it('parses multiple SSE events', function () {
    $sseData = "data: First\n\ndata: Second\n\ndata: Third\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(3)
        ->and($events[0]->data)->toBe('First')
        ->and($events[1]->data)->toBe('Second')
        ->and($events[2]->data)->toBe('Third');
});

it('parses event with id and type', function () {
    $sseData = "id: 123\nevent: custom\ndata: Test\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events[0]->id)->toBe('123')
        ->and($events[0]->event)->toBe('custom')
        ->and($events[0]->data)->toBe('Test');
});

it('parses multiline data field', function () {
    $sseData = "data: Line 1\ndata: Line 2\ndata: Line 3\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events[0]->data)->toBe("Line 1\nLine 2\nLine 3");
});

it('parses retry field', function () {
    $sseData = "retry: 5000\ndata: Test\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events[0]->retry)->toBe(5000)
        ->and($events[0]->data)->toBe('Test');
});

it('parses all field types together', function () {
    $sseData = "id: event-1\nevent: notification\ndata: Hello\nretry: 3000\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events[0]->id)->toBe('event-1')
        ->and($events[0]->event)->toBe('notification')
        ->and($events[0]->data)->toBe('Hello')
        ->and($events[0]->retry)->toBe(3000);
});

it('ignores comment lines', function () {
    $sseData = ": This is a comment\ndata: Real data\n: Another comment\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(1)
        ->and($events[0]->data)->toBe('Real data');
});

it('ignores empty lines within event', function () {
    $sseData = "data: First line\n\ndata: Second line\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(2);
});

it('buffers incomplete events', function () {
    $stream = Stream::fromString('');
    $response = new SSEResponse($stream, 200);

    $events1 = iterator_to_array($response->parseEvents("data: Par"));
    $events2 = iterator_to_array($response->parseEvents("tial\n\n"));

    expect($events1)->toHaveCount(0)
        ->and($events2)->toHaveCount(1)
        ->and($events2[0]->data)->toBe('Partial');
});

it('handles multiple incomplete chunks', function () {
    $stream = Stream::fromString('');
    $response = new SSEResponse($stream, 200);

    iterator_to_array($response->parseEvents("data: "));
    iterator_to_array($response->parseEvents("First "));
    iterator_to_array($response->parseEvents("part"));
    $events = iterator_to_array($response->parseEvents("\n\n"));

    expect($events)->toHaveCount(1)
        ->and($events[0]->data)->toBe('First part');
});

it('tracks last event id', function () {
    $sseData = "id: 100\ndata: First\n\nid: 200\ndata: Second\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    iterator_to_array($response->parseEvents($sseData));

    expect($response->getLastEventId())->toBe('200');
});

it('updates last event id progressively', function () {
    $stream = Stream::fromString('');
    $response = new SSEResponse($stream, 200);

    expect($response->getLastEventId())->toBeNull();

    iterator_to_array($response->parseEvents("id: 1\ndata: First\n\n"));
    expect($response->getLastEventId())->toBe('1');

    iterator_to_array($response->parseEvents("id: 2\ndata: Second\n\n"));
    expect($response->getLastEventId())->toBe('2');
});

it('does not update last event id when not present', function () {
    $stream = Stream::fromString('');
    $response = new SSEResponse($stream, 200);

    iterator_to_array($response->parseEvents("id: original\ndata: First\n\n"));
    iterator_to_array($response->parseEvents("data: No ID\n\n"));

    expect($response->getLastEventId())->toBe('original');
});

it('gets events from stream', function () {
    $sseData = "data: Event 1\n\ndata: Event 2\n\ndata: Event 3\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->getEvents());

    expect($events)->toHaveCount(3)
        ->and($events[0]->data)->toBe('Event 1')
        ->and($events[1]->data)->toBe('Event 2')
        ->and($events[2]->data)->toBe('Event 3');
});

it('handles field without colon', function () {
    $sseData = "data\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events[0]->data)->toBe('');
});

it('handles field with colon but no value', function () {
    $sseData = "data:\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events[0]->data)->toBe('');
});

it('trims leading space from field value', function () {
    $sseData = "data:  Value with spaces  \n\n";
    
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));
    
    if (count($events) > 0) {
        echo "Event[0] data: " . var_export($events[0]->data, true) . "\n";
        echo "Event[0] data length: " . strlen($events[0]->data) . "\n";
        echo "Event[0] data hex: " . bin2hex($events[0]->data) . "\n";
        echo "Event[0] rawFields: " . var_export($events[0]->rawFields, true) . "\n";
    }

    expect($events[0]->data)->toBe('Value with spaces  ');
});

it('handles CRLF line endings', function () {
    $sseData = "data: Test\r\n\r\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(1)
        ->and($events[0]->data)->toBe('Test');
});

it('handles mixed line endings', function () {
    $sseData = "data: First\r\n\r\ndata: Second\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(2)
        ->and($events[0]->data)->toBe('First')
        ->and($events[1]->data)->toBe('Second');
});

it('handles retry with non-numeric value', function () {
    $sseData = "retry: not-a-number\ndata: Test\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events[0]->retry)->toBeNull();
});

it('stores raw fields', function () {
    $sseData = "id: 1\nevent: test\ndata: Hello\ncustom: value\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events[0]->rawFields)->toHaveKey('id')
        ->and($events[0]->rawFields)->toHaveKey('event')
        ->and($events[0]->rawFields)->toHaveKey('data')
        ->and($events[0]->rawFields)->toHaveKey('custom')
        ->and($events[0]->rawFields['custom'])->toBe(['value']);
});

it('handles duplicate field names', function () {
    $sseData = "id: first\nid: second\nid: third\ndata: Test\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events[0]->id)->toBe('third') // Last one wins
        ->and($events[0]->rawFields['id'])->toBe(['first', 'second', 'third']);
});

it('processes buffered data at end of stream', function () {
    $stream = Stream::fromString("data: Incomplete event");
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->getEvents());

    expect($events)->toHaveCount(1)
        ->and($events[0]->data)->toBe('Incomplete event');
});

it('handles empty stream', function () {
    $stream = Stream::fromString('');
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->getEvents());

    expect($events)->toHaveCount(0);
});

it('handles stream with only comments', function () {
    $sseData = ": comment 1\n: comment 2\n: comment 3\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(0);
});

it('handles stream with only whitespace', function () {
    $sseData = "   \n\n   \n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(0);
});

it('ignores events with only empty fields', function () {
    $sseData = "\n\n\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events)->toHaveCount(0);
});

it('preserves response status and headers', function () {
    $stream = Stream::fromString('data: test\n\n');
    $headers = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
    ];
    $response = new SSEResponse($stream, 200, $headers);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/event-stream')
        ->and($response->getHeaderLine('Cache-Control'))->toBe('no-cache');
});

it('inherits from StreamingResponse', function () {
    $stream = Stream::fromString('data: test\n\n');
    $response = new SSEResponse($stream, 200);

    expect($response)->toBeInstanceOf(\Hibla\HttpClient\StreamingResponse::class);
});

it('handles field values with colons', function () {
    $sseData = "data: http://example.com:8080/path\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events[0]->data)->toBe('http://example.com:8080/path');
});

it('handles very long data fields', function () {
    $longData = str_repeat('x', 10000);
    $sseData = "data: {$longData}\n\n";
    $stream = Stream::fromString($sseData);
    $response = new SSEResponse($stream, 200);

    $events = iterator_to_array($response->parseEvents($sseData));

    expect($events[0]->data)->toBe($longData);
});

it('handles multiple events with buffering', function () {
    $stream = Stream::fromString('');
    $response = new SSEResponse($stream, 200);

    $allEvents = [];
    $allEvents = array_merge($allEvents, iterator_to_array($response->parseEvents("data: First\n")));
    $allEvents = array_merge($allEvents, iterator_to_array($response->parseEvents("\ndata: Sec")));
    $allEvents = array_merge($allEvents, iterator_to_array($response->parseEvents("ond\n\ndata: Thi")));
    $allEvents = array_merge($allEvents, iterator_to_array($response->parseEvents("rd\n\n")));

    expect($allEvents)->toHaveCount(3);
});
