<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class DuplicateKeyException extends HttpException
{
    public function __construct(string $message = 'Duplicate key')
    {
        parent::__construct(409, $message);
    }
}

