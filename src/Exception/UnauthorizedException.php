<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Unauthorized')
    {
        parent::__construct(401, $message);
    }
}

