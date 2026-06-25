<?php

namespace App\Dto;

class ErrorResponse
{
    public function __construct(
        public string $error,
        public int $status,
        public ?\DateTimeImmutable $timestamp = null,
    ) {
        if ($this->timestamp === null) {
            $this->timestamp = new \DateTimeImmutable();
        }
    }
}


