<?php

namespace App\Controller\Api;

use App\Exception\ReservationExpiredException;
use App\Messenger\Message\SendOrderConfirmation;
use App\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class OrderController extends AbstractController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route('/api/v1/orders', name: 'api_v1_orders_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);

        $reservationUuid = isset($body['reservationUuid']) ? trim($body['reservationUuid']) : null;
        $email           = isset($body['email']) ? trim($body['email']) : null;

        if (!is_string($reservationUuid) || $reservationUuid === '') {
            return new JsonResponse(
                ['error' => 'reservationUuid is required'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(
                ['error' => 'email must be a valid email address'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $orderNumber = $this->orderService->placeOrder($reservationUuid, $email);
        } catch (ReservationExpiredException) {
            return new JsonResponse(
                ['error' => 'Reservation has expired or does not exist. Please reserve again.'],
                JsonResponse::HTTP_GONE,
            );
        }

        $this->bus->dispatch(new SendOrderConfirmation($orderNumber, $email));

        return new JsonResponse(
            ['orderNumber' => $orderNumber],
            JsonResponse::HTTP_CREATED,
        );
    }
}
