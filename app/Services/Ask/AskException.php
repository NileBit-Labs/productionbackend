<?php

namespace App\Services\Ask;

use RuntimeException;

/** Something the person can be told about plainly; `code` lets the screen react to it. */
class AskException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}
