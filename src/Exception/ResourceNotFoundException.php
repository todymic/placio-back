<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ResourceNotFoundException extends HttpException
{
    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct(404, $message);
    }
}

