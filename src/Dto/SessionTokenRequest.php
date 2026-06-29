<?php

namespace App\Dto;

class SessionTokenRequest
{
    public function __construct(
        public readonly string $eventId,
    ) {
    }
}
