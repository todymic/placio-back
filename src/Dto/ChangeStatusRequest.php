<?php

namespace App\Dto;

class ChangeStatusRequest
{
    public function __construct(
        public array $seatKeys,
        public string $status,
    ) {
    }
}

