<?php

namespace App\Exception;

class OrderAlreadyCancelledException extends \RuntimeException
{
    public function __construct(string $orderNumber)
    {
        parent::__construct(sprintf('Order "%s" is already cancelled.', $orderNumber));
    }
}
