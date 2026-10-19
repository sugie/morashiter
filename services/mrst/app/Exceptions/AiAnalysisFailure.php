<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised for AI misconfiguration or a failed analysis. Messages never include
 * the API key or the provider's response body, and no previous exception is
 * chained because transport exceptions can carry request headers.
 */
class AiAnalysisFailure extends RuntimeException {}
