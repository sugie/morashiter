<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class HttpFetchFailure extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly string $url,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
