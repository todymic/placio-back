<?php

namespace App\Dto;

class EventRequest
{
    public function __construct(
        public string $title,
        public string $identifier,
        public ?string $chartId = null,
    ) {
    }
}

