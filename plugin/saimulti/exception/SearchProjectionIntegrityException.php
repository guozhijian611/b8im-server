<?php

declare(strict_types=1);

namespace plugin\saimulti\exception;

/** Reportable failure caused by inconsistent authoritative IM/search state. */
final class SearchProjectionIntegrityException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 503,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
