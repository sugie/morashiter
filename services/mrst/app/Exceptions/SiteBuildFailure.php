<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The static build or its verification refused the snapshot; nothing was uploaded.
 */
class SiteBuildFailure extends RuntimeException {}
