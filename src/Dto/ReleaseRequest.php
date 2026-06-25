<?php

namespace App\Dto;

class ReleaseRequest
{
    public function __construct(
        public array $seatKeys,
        public string $holdToken,
    ) {
    }
}

