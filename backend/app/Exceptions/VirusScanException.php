<?php

namespace App\Exceptions;

use RuntimeException;

/** The virus scanner could not complete a scan (unreachable, timeout, protocol error). */
class VirusScanException extends RuntimeException
{
}
