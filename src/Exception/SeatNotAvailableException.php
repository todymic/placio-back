<?php

namespace App\Exception;

use App\Dto\SeatConflictDetail;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SeatNotAvailableException extends HttpException
{
    public function __construct(
        public array $conflicts = [],
        string $message = 'One or more seats are not available'
    ) {
        parent::__construct(409, $message);
    }
}

