<?php

namespace App\Exceptions;

use RuntimeException;

class FeedParseFailure extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
