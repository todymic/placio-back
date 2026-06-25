<?php

namespace App\Entity;

enum ApiKeyScope: string
{
    case BACKOFFICE = 'backoffice';
    case PUBLIC = 'public';

    public function prefix(): string
    {
        return match ($this) {
            self::BACKOFFICE => 'bo',
            self::PUBLIC => 'pub',
        };
    }
}

