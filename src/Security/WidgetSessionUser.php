<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

class WidgetSessionUser implements UserInterface
{
    public function __construct(
        public readonly string $publicKeyId,
        public readonly string $eventId,
        public readonly string $holdToken,
    ) {
    }

    public function getRoles(): array
    {
        return ['ROLE_WIDGET'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->publicKeyId;
    }
}
