<?php

namespace App\Messenger\Message;

final readonly class SendOrderConfirmation
{
    public function __construct(
        public string $orderNumber,
        public string $email,
        public string $tierName,
        public string $eventName,
        public ?\DateTimeInterface $eventStartDate,
        public string $venueName,
        public int $quantity,
        public string $totalPrice,
        public string $currency,
    ) {}
}
