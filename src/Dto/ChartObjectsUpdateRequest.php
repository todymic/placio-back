<?php

namespace App\Dto;

class ChartObjectsUpdateRequest
{
    public function __construct(
        public array $objects,
    ) {
    }
}

