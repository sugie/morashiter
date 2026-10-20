<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Upload, CDN purge or confirmation failed after the build; the previous live release is restored.
 */
class PublishFailure extends RuntimeException {}
