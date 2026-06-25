<?php

namespace App\Dto;

class BookResponse
{
    public function __construct(
        public array $bookedSeats,
        public string $eventId,
        public \DateTimeImmutable $bookedAt,
    ) {
    }
}

