<?php

namespace App\Controller\Api;

use App\Exception\ReservationExpiredException;
use App\Service\OrderService;
use Pimcore\Model\DataObject\Order;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class OrderController extends AbstractController
{
    public function __construct(
        private readonly OrderService $orderService,
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

        return new JsonResponse(
            ['orderNumber' => $orderNumber],
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route('/api/v1/orders/{orderNumber}', name: 'api_v1_orders_show', methods: ['GET'])]
    public function show(string $orderNumber, Request $request): JsonResponse
    {
        $email = $request->query->get('email', '');

        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(
                ['error' => 'email query parameter must be a valid email address'],
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }

        /** @var Order|null $order */
        $order = Order::getByOrderNumber($orderNumber, 1);

        if ($order === null || $order->getEmail() !== $email) {
            return new JsonResponse(
                ['error' => 'Order not found'],
                JsonResponse::HTTP_NOT_FOUND,
            );
        }

        $tier  = $order->getTier();
        $event = $tier?->getEvent();
        $venue = $event?->getVenue();

        return new JsonResponse([
            'orderNumber'    => $order->getOrderNumber(),
            'status'         => $order->getStatus(),
            'quantity'       => (int) $order->getQuantity(),
            'totalPrice'     => number_format((float) $order->getTotalPrice(), 2),
            'currency'       => $tier?->getCurrency() ?? 'USD',
            'tierName'       => $tier?->getName() ?? '',
            'eventName'      => $event?->getName() ?? '',
            'eventStartDate' => $event?->getStartDate()?->format(\DateTimeInterface::ATOM),
            'eventSlug'      => $event?->getSlug() ?? '',
            'venueName'      => $venue?->getName() ?? '',
        ]);
    }
}
