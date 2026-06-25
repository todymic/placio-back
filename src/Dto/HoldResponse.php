<?php

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

class HoldResponse
{
    public function __construct(
        public string $holdToken,
        public array $seatKeys,
        public \DateTimeImmutable $expiresAt,
        public int $durationSeconds,
    ) {
    }
}

