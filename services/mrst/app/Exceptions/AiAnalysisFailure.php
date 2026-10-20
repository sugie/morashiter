<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised for AI misconfiguration or a failed analysis. Messages never include
 * the API key or the provider's response body, and no previous exception is
 * chained because transport exceptions can carry request headers.
 *
 * retryable: true when trying the same document again later may succeed (malformed model
 * output, timeouts, 429/5xx); false when it will keep failing (4xx such as a bad key).
 */
class AiAnalysisFailure extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
