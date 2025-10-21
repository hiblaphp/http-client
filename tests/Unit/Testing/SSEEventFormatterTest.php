<?php

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\Testing\Utilities\Formatters\SSEEventFormatter;

describe('SSEEventFormatter', function () {

    describe('formatEvents', function () {
        it('formats empty array to empty string', function () {
            $formatter = new SSEEventFormatter();

            $result = $formatter->formatEvents([]);

            expect($result)->toBe('');
        });

        it('formats single event with all fields', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                [
                    'id' => '123',
                    'event' => 'message',
                    'data' => 'Hello World',
                    'retry' => 3000,
                ],
            ];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("id: 123\nevent: message\nretry: 3000\ndata: Hello World\n\n");
        });

        it('formats single event with only data', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                ['data' => 'Simple message'],
            ];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: Simple message\n\n");
        });

        it('formats multiple events', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                ['data' => 'First message'],
                ['data' => 'Second message'],
                ['data' => 'Third message'],
            ];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe(
                "data: First message\n\n" .
                    "data: Second message\n\n" .
                    "data: Third message\n\n"
            );
        });

        it('formats events with different field combinations', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                ['id' => '1', 'data' => 'Message 1'],
                ['event' => 'update', 'data' => 'Message 2'],
                ['id' => '3', 'event' => 'notification', 'data' => 'Message 3'],
            ];

            $result = $formatter->formatEvents($events);

            expect($result)->toContain("id: 1\ndata: Message 1\n\n")
                ->and($result)->toContain("event: update\ndata: Message 2\n\n")
                ->and($result)->toContain("id: 3\nevent: notification\ndata: Message 3\n\n")
            ;
        });

        it('formats multiline data across multiple events', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                ['data' => "Line 1\nLine 2"],
                ['data' => "Another\nMultiline\nMessage"],
            ];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe(
                "data: Line 1\ndata: Line 2\n\n" .
                    "data: Another\ndata: Multiline\ndata: Message\n\n"
            );
        });
    });

    describe('formatSingleEvent - Field Order', function () {
        it('formats fields in correct order: id, event, retry, data', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                [
                    'data' => 'Hello',
                    'retry' => 5000,
                    'event' => 'message',
                    'id' => '42',
                ],
            ];

            $result = $formatter->formatEvents($events);

            // Should be ordered: id, event, retry, data
            expect($result)->toBe("id: 42\nevent: message\nretry: 5000\ndata: Hello\n\n");
        });
    });

    describe('formatSingleEvent - Individual Fields', function () {
        it('formats event with only id', function () {
            $formatter = new SSEEventFormatter();
            $events = [['id' => 'event-123']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("id: event-123\n\n");
        });

        it('formats event with only event type', function () {
            $formatter = new SSEEventFormatter();
            $events = [['event' => 'notification']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("event: notification\n\n");
        });

        it('formats event with only retry', function () {
            $formatter = new SSEEventFormatter();
            $events = [['retry' => 10000]];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("retry: 10000\n\n");
        });

        it('formats event with only data', function () {
            $formatter = new SSEEventFormatter();
            $events = [['data' => 'Just data']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: Just data\n\n");
        });

        it('ignores non-string id field', function () {
            $formatter = new SSEEventFormatter();
            $events = [['id' => 123, 'data' => 'test']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: test\n\n");
        });

        it('ignores non-string event field', function () {
            $formatter = new SSEEventFormatter();
            $events = [['event' => 123, 'data' => 'test']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: test\n\n");
        });

        it('ignores non-int retry field', function () {
            $formatter = new SSEEventFormatter();
            $events = [['retry' => '5000', 'data' => 'test']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: test\n\n");
        });

        it('ignores non-string data field', function () {
            $formatter = new SSEEventFormatter();
            $events = [['data' => 123, 'id' => 'test']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("id: test\n\n");
        });
    });

    describe('formatSingleEvent - Multiline Data', function () {
        it('formats single line data', function () {
            $formatter = new SSEEventFormatter();
            $events = [['data' => 'Single line']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: Single line\n\n");
        });

        it('formats two line data', function () {
            $formatter = new SSEEventFormatter();
            $events = [['data' => "Line 1\nLine 2"]];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: Line 1\ndata: Line 2\n\n");
        });

        it('formats multiple line data', function () {
            $formatter = new SSEEventFormatter();
            $events = [['data' => "Line 1\nLine 2\nLine 3\nLine 4"]];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: Line 1\ndata: Line 2\ndata: Line 3\ndata: Line 4\n\n");
        });

        it('handles empty lines in multiline data', function () {
            $formatter = new SSEEventFormatter();
            $events = [['data' => "Line 1\n\nLine 3"]];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: Line 1\ndata: \ndata: Line 3\n\n");
        });

        it('handles trailing newline in data', function () {
            $formatter = new SSEEventFormatter();
            $events = [['data' => "Line 1\nLine 2\n"]];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: Line 1\ndata: Line 2\ndata: \n\n");
        });

        it('formats JSON data as multiline when it contains newlines', function () {
            $formatter = new SSEEventFormatter();
            $jsonData = "{\n  \"key\": \"value\"\n}";
            $events = [['data' => $jsonData]];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: {\ndata:   \"key\": \"value\"\ndata: }\n\n");
        });
    });

    describe('createSSEEvent', function () {
        it('creates SSEEvent with all fields', function () {
            $formatter = new SSEEventFormatter();
            $eventData = [
                'id' => '123',
                'event' => 'message',
                'data' => 'Hello World',
                'retry' => 3000,
            ];

            $event = $formatter->createSSEEvent($eventData);

            expect($event)->toBeInstanceOf(SSEEvent::class)
                ->and($event->id)->toBe('123')
                ->and($event->event)->toBe('message')
                ->and($event->data)->toBe('Hello World')
                ->and($event->retry)->toBe(3000)
            ;
        });

        it('creates SSEEvent with only data', function () {
            $formatter = new SSEEventFormatter();
            $eventData = ['data' => 'Simple message'];

            $event = $formatter->createSSEEvent($eventData);

            expect($event)->toBeInstanceOf(SSEEvent::class)
                ->and($event->id)->toBeNull()
                ->and($event->event)->toBeNull()
                ->and($event->data)->toBe('Simple message')
                ->and($event->retry)->toBeNull()
            ;
        });

        it('creates SSEEvent with no fields', function () {
            $formatter = new SSEEventFormatter();
            $eventData = [];

            $event = $formatter->createSSEEvent($eventData);

            expect($event)->toBeInstanceOf(SSEEvent::class)
                ->and($event->id)->toBeNull()
                ->and($event->event)->toBeNull()
                ->and($event->data)->toBeNull()
                ->and($event->retry)->toBeNull()
            ;
        });

        it('creates SSEEvent with only id', function () {
            $formatter = new SSEEventFormatter();
            $eventData = ['id' => 'event-456'];

            $event = $formatter->createSSEEvent($eventData);

            expect($event->id)->toBe('event-456')
                ->and($event->data)->toBeNull()
            ;
        });

        it('creates SSEEvent with only event type', function () {
            $formatter = new SSEEventFormatter();
            $eventData = ['event' => 'notification'];

            $event = $formatter->createSSEEvent($eventData);

            expect($event->event)->toBe('notification')
                ->and($event->data)->toBeNull()
            ;
        });

        it('creates SSEEvent with only retry', function () {
            $formatter = new SSEEventFormatter();
            $eventData = ['retry' => 5000];

            $event = $formatter->createSSEEvent($eventData);

            expect($event->retry)->toBe(5000)
                ->and($event->data)->toBeNull()
            ;
        });

        it('creates SSEEvent with multiline data', function () {
            $formatter = new SSEEventFormatter();
            $eventData = ['data' => "Line 1\nLine 2\nLine 3"];

            $event = $formatter->createSSEEvent($eventData);

            expect($event->data)->toBe("Line 1\nLine 2\nLine 3");
        });

        it('includes rawFields with id', function () {
            $formatter = new SSEEventFormatter();
            $eventData = ['id' => '789'];

            $event = $formatter->createSSEEvent($eventData);

            expect($event->rawFields)->toHaveKey('id')
                ->and($event->rawFields['id'])->toBe(['789'])
            ;
        });

        it('includes rawFields with event', function () {
            $formatter = new SSEEventFormatter();
            $eventData = ['event' => 'update'];

            $event = $formatter->createSSEEvent($eventData);

            expect($event->rawFields)->toHaveKey('event')
                ->and($event->rawFields['event'])->toBe(['update'])
            ;
        });

        it('includes rawFields with data', function () {
            $formatter = new SSEEventFormatter();
            $eventData = ['data' => 'Test data'];

            $event = $formatter->createSSEEvent($eventData);

            expect($event->rawFields)->toHaveKey('data')
                ->and($event->rawFields['data'])->toBe(['Test data'])
            ;
        });

        it('includes rawFields with retry as string', function () {
            $formatter = new SSEEventFormatter();
            $eventData = ['retry' => 7000];

            $event = $formatter->createSSEEvent($eventData);

            expect($event->rawFields)->toHaveKey('retry')
                ->and($event->rawFields['retry'])->toBe(['7000'])
            ;
        });

        it('includes all fields in rawFields', function () {
            $formatter = new SSEEventFormatter();
            $eventData = [
                'id' => '100',
                'event' => 'custom',
                'data' => 'Data here',
                'retry' => 2000,
            ];

            $event = $formatter->createSSEEvent($eventData);

            expect($event->rawFields)->toHaveKey('id')
                ->and($event->rawFields)->toHaveKey('event')
                ->and($event->rawFields)->toHaveKey('data')
                ->and($event->rawFields)->toHaveKey('retry')
                ->and($event->rawFields['id'])->toBe(['100'])
                ->and($event->rawFields['event'])->toBe(['custom'])
                ->and($event->rawFields['data'])->toBe(['Data here'])
                ->and($event->rawFields['retry'])->toBe(['2000'])
            ;
        });
    });

    describe('Edge Cases', function () {
        it('handles empty string values', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                [
                    'id' => '',
                    'event' => '',
                    'data' => '',
                ],
            ];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("id: \nevent: \ndata: \n\n");
        });

        it('handles zero as retry value', function () {
            $formatter = new SSEEventFormatter();
            $events = [['retry' => 0, 'data' => 'test']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("retry: 0\ndata: test\n\n");
        });

        it('handles negative retry value', function () {
            $formatter = new SSEEventFormatter();
            $events = [['retry' => -100, 'data' => 'test']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("retry: -100\ndata: test\n\n");
        });

        it('handles very large retry value', function () {
            $formatter = new SSEEventFormatter();
            $events = [['retry' => 999999999, 'data' => 'test']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("retry: 999999999\ndata: test\n\n");
        });

        it('handles special characters in id', function () {
            $formatter = new SSEEventFormatter();
            $events = [['id' => 'id-with-special-chars-@#$%', 'data' => 'test']];

            $result = $formatter->formatEvents($events);

            expect($result)->toContain('id: id-with-special-chars-@#$%');
        });

        it('handles special characters in event type', function () {
            $formatter = new SSEEventFormatter();
            $events = [['event' => 'event:type/special', 'data' => 'test']];

            $result = $formatter->formatEvents($events);

            expect($result)->toContain('event: event:type/special');
        });

        it('handles unicode characters in data', function () {
            $formatter = new SSEEventFormatter();
            $events = [['data' => 'Hello 世界 🌍']];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: Hello 世界 🌍\n\n");
        });

        it('handles very long data string', function () {
            $formatter = new SSEEventFormatter();
            $longData = str_repeat('A', 10000);
            $events = [['data' => $longData]];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: {$longData}\n\n");
        });

        it('handles event with unknown fields', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                [
                    'data' => 'test',
                    'unknown_field' => 'ignored',
                    'another_field' => 123,
                ],
            ];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("data: test\n\n");
        });

        it('formats empty event object', function () {
            $formatter = new SSEEventFormatter();
            $events = [[]];

            $result = $formatter->formatEvents($events);

            expect($result)->toBe("\n\n");
        });

        it('handles null values in event data for createSSEEvent', function () {
            $formatter = new SSEEventFormatter();
            $eventData = [
                'id' => null,
                'event' => null,
                'data' => null,
                'retry' => null,
            ];

            $event = $formatter->createSSEEvent($eventData);

            // Null values should not be set, so they should be null in the event
            expect($event->id)->toBeNull()
                ->and($event->event)->toBeNull()
                ->and($event->data)->toBeNull()
                ->and($event->retry)->toBeNull()
            ;
        });
    });

    describe('Integration Scenarios', function () {
        it('formats a chat message stream', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                ['id' => '1', 'event' => 'message', 'data' => 'User joined'],
                ['id' => '2', 'event' => 'message', 'data' => 'Hello everyone!'],
                ['id' => '3', 'event' => 'message', 'data' => 'How are you?'],
            ];

            $result = $formatter->formatEvents($events);

            expect($result)->toContain("id: 1\nevent: message\ndata: User joined\n\n")
                ->and($result)->toContain("id: 2\nevent: message\ndata: Hello everyone!\n\n")
                ->and($result)->toContain("id: 3\nevent: message\ndata: How are you?\n\n")
            ;
        });

        it('formats a progress update stream', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                ['event' => 'progress', 'data' => '{"percent": 0}'],
                ['event' => 'progress', 'data' => '{"percent": 50}'],
                ['event' => 'progress', 'data' => '{"percent": 100}'],
                ['event' => 'complete', 'data' => 'Done!'],
            ];

            $result = $formatter->formatEvents($events);

            expect($result)->toContain('event: progress')
                ->and($result)->toContain('event: complete')
                ->and($result)->toContain('data: {"percent": 0}')
                ->and($result)->toContain('data: Done!')
            ;
        });

        it('creates SSEEvent objects for a stream', function () {
            $formatter = new SSEEventFormatter();
            $eventsData = [
                ['id' => '1', 'data' => 'First'],
                ['id' => '2', 'data' => 'Second'],
                ['id' => '3', 'data' => 'Third'],
            ];

            $events = array_map(
                fn ($eventData) => $formatter->createSSEEvent($eventData),
                $eventsData
            );

            expect($events)->toHaveCount(3);
            foreach ($events as $index => $event) {
                expect($event)->toBeInstanceOf(SSEEvent::class)
                    ->and($event->id)->toBe((string)($index + 1))
                ;
            }
        });

        it('formats heartbeat events', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                ['event' => 'heartbeat', 'data' => ''],
                ['event' => 'heartbeat', 'data' => ''],
                ['event' => 'heartbeat', 'data' => ''],
            ];

            $result = $formatter->formatEvents($events);

            expect(substr_count($result, 'event: heartbeat'))->toBe(3);
        });

        it('formats mixed event types with retry configuration', function () {
            $formatter = new SSEEventFormatter();
            $events = [
                ['retry' => 3000],
                ['id' => '1', 'event' => 'start', 'data' => 'Starting...'],
                ['id' => '2', 'event' => 'update', 'data' => 'Processing...'],
                ['id' => '3', 'event' => 'end', 'data' => 'Completed!'],
            ];

            $result = $formatter->formatEvents($events);

            expect($result)->toContain("retry: 3000\n\n")
                ->and($result)->toContain('event: start')
                ->and($result)->toContain('event: update')
                ->and($result)->toContain('event: end')
            ;
        });
    });
});
