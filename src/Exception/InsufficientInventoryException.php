<?php

namespace App\Exception;

class InsufficientInventoryException extends \RuntimeException
{
    public function __construct(int $tierId, int $quantity)
    {
        parent::__construct(
            sprintf('Not enough tickets: tier %d has fewer than %d available', $tierId, $quantity),
        );
    }
}
