<?php

namespace App\Actions\Sessions;

use RuntimeException;
use Throwable;

class SessionRevocationException extends RuntimeException
{
    public function __construct(public readonly bool $rememberMeReset, ?Throwable $previous = null)
    {
        parent::__construct('Sessions could not be revoked.', 0, $previous);
    }
}
