<?php

namespace App\Dto;

class ApiKeyRequest
{
    public function __construct(
        public string $name,
        public string $scope,
    ) {
    }
}

