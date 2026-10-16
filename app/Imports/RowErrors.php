<?php

namespace App\Imports;

use RuntimeException;

/** The unreadable cells of one document, each on its own row of the file. */
class RowErrors extends RuntimeException
{
    /** @param list<array{row: int, message: string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0]['message'] ?? '');
    }
}
