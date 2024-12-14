<?php

namespace Bnomei;

class AbortCachingException extends \Exception
{
    public function __construct(string $message = 'Abort Caching', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
