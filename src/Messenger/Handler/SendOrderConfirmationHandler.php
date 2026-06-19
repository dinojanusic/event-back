<?php

namespace App\Messenger\Handler;

use App\Messenger\Message\SendOrderConfirmation;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final class SendOrderConfirmationHandler
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $senderEmail,
        private readonly string $senderName,
    ) {}

    public function __invoke(SendOrderConfirmation $message): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->senderEmail, $this->senderName))
            ->to($message->email)
            ->subject('Your tickets for ' . $message->eventName)
            ->htmlTemplate('emails/order_confirmation.html.twig')
            ->context(['message' => $message]);

        $this->mailer->send($email);

        $this->logger->info('Order confirmation sent', [
            'orderNumber' => $message->orderNumber,
            'email'       => $message->email,
        ]);
    }
}
