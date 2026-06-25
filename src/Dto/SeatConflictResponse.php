<?php

namespace App\Dto;

class SeatConflictResponse
{
    public function __construct(
        public string $message,
        public array $conflicts = [],
    ) {
    }
}

