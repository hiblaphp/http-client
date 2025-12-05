<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Traits;

use Hibla\Promise\CancellablePromise;

trait CancellablePromiseTrait
{
    /**
     * @template TValue
     * @param TValue $value
     * @return CancellablePromise<TValue>
     */
    private function resolved(mixed $value): CancellablePromise
    {
        /** @var CancellablePromise<TValue> $promise */
        $promise = new CancellablePromise();

        $promise->resolve($value);

        return $promise;
    }

    /**
     * @return CancellablePromise<mixed>
     */
    private function rejected(mixed $reason): CancellablePromise
    {
        /** @var CancellablePromise<mixed> $promise */
        $promise = new CancellablePromise();

        $promise->reject($reason);

        return $promise;
    }
}
