<?php

namespace App\Dto;

class BookRequest
{
    public function __construct(
        public array $seatKeys,
        public string $holdToken,
    ) {
    }
}

