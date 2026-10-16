<?php

namespace App\Imports;

use RuntimeException;

/** A row of an uploaded file that cannot be read as written (the message says why). */
class ImportError extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
