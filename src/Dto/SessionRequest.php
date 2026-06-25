<?php

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

class SessionRequest
{
    public function __construct(
        public Uuid $eventId,
    ) {
    }
}

