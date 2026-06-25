<?php

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

class ApiKeyCreatedResponse
{
    public function __construct(
        public Uuid $id,
        public string $name,
        public string $keyId,
        public string $secret,
        public string $scope,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}

