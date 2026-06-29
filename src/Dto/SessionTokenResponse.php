<?php

namespace App\Dto;

class SessionTokenResponse
{
    public function __construct(
        public readonly string $sessionToken,
        public readonly string $holdToken,
        public readonly int $expiresIn,
        public readonly string $eventId,
    ) {
    }
}
