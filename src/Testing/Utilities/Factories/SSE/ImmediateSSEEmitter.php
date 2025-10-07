<?php

namespace Hibla\HttpClient\Testing\Utilities\Factories\SSE;

use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Formatters\SSEEventFormatter;
use Hibla\Promise\CancellablePromise;

class ImmediateSSEEmitter
{
    private SSEEventFormatter $formatter;

    public function __construct()
    {
        $this->formatter = new SSEEventFormatter();
    }

    /**
     * @param CancellablePromise<SSEResponse> $promise
     * @param MockedRequest $mock
     * @param callable|null $onEvent
     * @param string|null &$lastEventId
     * @param int|null &$retryInterval
     */
    public function emit(
        CancellablePromise $promise,
        MockedRequest $mock,
        ?callable $onEvent,
        ?string &$lastEventId,
        ?int &$retryInterval
    ): void {
        $sseContent = $this->formatter->formatEvents($mock->getSSEEvents());

        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new HttpStreamException('Failed to create temporary stream');
        }

        fwrite($resource, $sseContent);
        rewind($resource);
        $stream = new Stream($resource);

        $sseResponse = new SSEResponse(
            $stream,
            $mock->getStatusCode(),
            $mock->getHeaders()
        );

        if ($onEvent !== null) {
            foreach ($mock->getSSEEvents() as $eventData) {
                $event = $this->formatter->createSSEEvent($eventData);

                if ($event->id !== null) {
                    $lastEventId = $event->id;
                }

                if ($event->retry !== null) {
                    $retryInterval = $event->retry;
                }

                $onEvent($event);
            }
        }

        $promise->resolve($sseResponse);
    }
}