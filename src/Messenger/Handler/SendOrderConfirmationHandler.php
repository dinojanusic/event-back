<?php

namespace App\Messenger\Handler;

use App\Messenger\Message\SendOrderConfirmation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendOrderConfirmationHandler
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function __invoke(SendOrderConfirmation $message): void
    {
        $this->logger->info('Sending order confirmation email', [
            'orderNumber' => $message->orderNumber,
            'email'       => $message->email,
        ]);
    }
}
