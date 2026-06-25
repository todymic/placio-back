<?php

namespace App\Dto;

use Symfony\Component\Uid\Uuid;

class ApiKeyResponse
{
    public function __construct(
        public Uuid $id,
        public string $name,
        public string $keyId,
        public string $scope,
        public bool $active,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastUsedAt = null,
    ) {
    }
}

