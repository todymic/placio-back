<?php

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

class SessionResponse
{
    public function __construct(
        public string $sessionToken,
        public Uuid $eventId,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}

