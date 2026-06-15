<?php

namespace App\Messenger\Message;

final readonly class SendOrderConfirmation
{
    public function __construct(
        public string $orderNumber,
        public string $email,
    ) {}
}
