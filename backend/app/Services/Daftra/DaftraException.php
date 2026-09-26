<?php

namespace App\Services\Daftra;

use RuntimeException;

class DaftraException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?array $responseBody = null,
    ) {
        parent::__construct($message);
    }
}
