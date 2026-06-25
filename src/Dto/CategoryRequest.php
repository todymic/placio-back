<?php

namespace App\Dto;

class CategoryRequest
{
    public function __construct(
        public string $name,
        public string $key,
        public string $color,
    ) {
    }
}

