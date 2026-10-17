<?php

namespace App\Exceptions;

use RuntimeException;

class UrlPolicyViolation extends RuntimeException
{
    public function __construct(
        public readonly string $violationCode,
        public readonly string $offendingUrl,
        string $message,
    ) {
        parent::__construct($message);
    }
}
