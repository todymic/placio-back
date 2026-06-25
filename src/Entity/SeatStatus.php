<?php

namespace App\Entity;

enum SeatStatus: string
{
    case AVAILABLE = 'available';
    case HOLD = 'hold';
    case BOOKED = 'booked';
    case CANCELED = 'canceled';
}

