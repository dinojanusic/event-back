<?php

namespace App\Exception;

class OrderNotFoundException extends \RuntimeException
{
    public function __construct(string $orderNumber)
    {
        parent::__construct(sprintf('Order "%s" not found.', $orderNumber));
    }
}
