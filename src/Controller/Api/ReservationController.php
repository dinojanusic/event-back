<?php

namespace App\Controller\Api;

use App\Exception\InsufficientInventoryException;
use App\Service\ReservationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ReservationController extends AbstractController
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    #[Route('/api/v1/reservations', name: 'api_v1_reservations_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);

        $tierId   = $body['tierId'] ?? null;
        $quantity = $body['quantity'] ?? null;

        if (!is_int($tierId) || $tierId <= 0 || !is_int($quantity) || $quantity <= 0) {
            return new JsonResponse(
                ['error' => 'tierId and quantity must be positive integers'],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $result = $this->reservationService->reserve($tierId, $quantity);
        } catch (InsufficientInventoryException) {
            return new JsonResponse(
                ['error' => 'Not enough tickets available'],
                JsonResponse::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(
            ['uuid' => $result['uuid'], 'expiresAt' => $result['expiresAt']],
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route('/api/v1/reservations/{uuid}', name: 'api_v1_reservations_delete', methods: ['DELETE'])]
    public function delete(string $uuid): JsonResponse
    {
        $this->reservationService->release($uuid);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
