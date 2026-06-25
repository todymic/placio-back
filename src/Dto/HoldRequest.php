<?php

namespace App\Dto;

class HoldRequest
{
    public function __construct(
        public array $seatKeys,
        public string $holdToken,
    ) {
    }
}

