<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class LicenseApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $unreachable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
