<?php

namespace App\Exception;

class ReservationExpiredException extends \RuntimeException
{
    public function __construct(string $uuid)
    {
        parent::__construct(sprintf('Reservation "%s" has expired or does not exist.', $uuid));
    }
}
