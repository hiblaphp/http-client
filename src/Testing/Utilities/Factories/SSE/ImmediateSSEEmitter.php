<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Factories\SSE;

use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Formatters\SSEEventFormatter;
use Hibla\Promise\Promise;

class ImmediateSSEEmitter
{
    private SSEEventFormatter $formatter;

    public function __construct()
    {
        $this->formatter = new SSEEventFormatter();
    }

    /**
     * @param Promise<SSEResponse> $promise
     * @param MockedRequest $mock
     * @param callable|null $onEvent
     * @param string|null &$lastEventId
     * @param int|null &$retryInterval
     */
    public function emit(
        Promise $promise,
        MockedRequest $mock,
        ?callable $onEvent,
        ?string &$lastEventId,
        ?int &$retryInterval
    ): void {
        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new HttpStreamException('Failed to create temporary stream');
        }

        $stream = new Stream($resource);
        $sseResponse = new SSEResponse(
            $stream,
            $mock->getStatusCode(),
            $mock->getHeaders()
        );

        $sseContent = $this->formatter->formatEvents($mock->getSSEEvents());
        $sseResponse->getStream()->write($sseContent);

        $events = $sseResponse->parseEvents($sseContent);

        foreach ($events as $event) {
            if ($event->id !== null) {
                $lastEventId = $event->id;
            }

            if ($event->retry !== null) {
                $retryInterval = $event->retry;
            }

            if ($onEvent !== null) {
                $onEvent($event);
            }
        }

        $promise->resolve($sseResponse);
    }
}
