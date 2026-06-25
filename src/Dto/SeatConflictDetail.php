<?php

namespace App\Dto;

class SeatConflictDetail
{
    public function __construct(
        public string $seatKey,
        public string $currentStatus,
    ) {
    }
}

