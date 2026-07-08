<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class MitelApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
