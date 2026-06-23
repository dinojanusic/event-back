<?php

namespace App\Controller\Api;

use App\Exception\OrderAlreadyCancelledException;
use App\Exception\OrderNotFoundException;
use App\Service\OrderService;
use Pimcore\Model\DataObject\Order;
use Pimcore\Model\DataObject\TicketTier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class AdminController extends AbstractController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly \Redis $inventoryRedis,
        private readonly string $adminApiKey,
    ) {}

    private function isAuthorized(Request $request): bool
    {
        return hash_equals($this->adminApiKey, (string) $request->headers->get('X-Admin-Key', ''));
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
    }

    private const PAGE_SIZE = 15;

    #[Route('/api/v1/admin/orders', name: 'api_v1_admin_orders_index', methods: ['GET'])]
    public function orders(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->unauthorizedResponse();
        }

        $page = max(1, (int) $request->query->get('page', 1));

        $listing = new Order\Listing();
        $listing->setOrderKey('creationDate');
        $listing->setOrder('desc');
        $listing->setLimit(self::PAGE_SIZE);
        $listing->setOffset(($page - 1) * self::PAGE_SIZE);

        if ($status = $request->query->get('status')) {
            $listing->filterByStatus($status);
        }

        $total  = $listing->getTotalCount();
        $orders = array_map(function (Order $order): array {
            $tier  = $order->getTier();
            $event = $tier?->getEvent();

            return [
                'orderNumber' => $order->getOrderNumber(),
                'email'       => $order->getEmail(),
                'status'      => $order->getStatus(),
                'quantity'    => (int) $order->getQuantity(),
                'totalPrice'  => number_format((float) $order->getTotalPrice(), 2),
                'currency'    => $tier?->getCurrency() ?? 'USD',
                'tierName'    => $tier?->getName() ?? '',
                'eventName'   => $event?->getName() ?? '',
                'createdAt'   => $order->getCreationDate()
                    ? (new \DateTime('@' . $order->getCreationDate()))->format(\DateTimeInterface::ATOM)
                    : null,
            ];
        }, $listing->getObjects());

        return new JsonResponse([
            'data'       => $orders,
            'page'       => $page,
            'pageSize'   => self::PAGE_SIZE,
            'total'      => $total,
            'totalPages' => (int) ceil($total / self::PAGE_SIZE),
        ]);
    }

    #[Route('/api/v1/admin/orders/{orderNumber}', name: 'api_v1_admin_orders_show', methods: ['GET'])]
    public function orderDetail(string $orderNumber, Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->unauthorizedResponse();
        }

        /** @var Order|null $order */
        $order = Order::getByOrderNumber($orderNumber, 1);

        if ($order === null) {
            return new JsonResponse(['error' => 'Order not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeOrderDetail($order));
    }

    #[Route('/api/v1/admin/orders/{orderNumber}/cancel', name: 'api_v1_admin_orders_cancel', methods: ['POST'])]
    public function cancelOrder(string $orderNumber, Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->unauthorizedResponse();
        }

        try {
            $order = $this->orderService->cancelOrder($orderNumber);
        } catch (OrderNotFoundException) {
            return new JsonResponse(['error' => 'Order not found'], JsonResponse::HTTP_NOT_FOUND);
        } catch (OrderAlreadyCancelledException) {
            return new JsonResponse(['error' => 'Order is already cancelled'], JsonResponse::HTTP_CONFLICT);
        }

        return new JsonResponse($this->serializeOrderDetail($order));
    }

    #[Route('/api/v1/admin/inventory', name: 'api_v1_admin_inventory', methods: ['GET'])]
    public function inventory(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->unauthorizedResponse();
        }

        $page = max(1, (int) $request->query->get('page', 1));

        $listing = new TicketTier\Listing();
        $listing->setLimit(self::PAGE_SIZE);
        $listing->setOffset(($page - 1) * self::PAGE_SIZE);

        $total = $listing->getTotalCount();
        $data  = array_map(function (TicketTier $tier): array {
            $available = (int) ($this->inventoryRedis->get(
                sprintf('tier:%d:available', $tier->getId())
            ) ?: 0);

            return [
                'tierId'    => $tier->getId(),
                'tierName'  => $tier->getName() ?? '',
                'eventName' => $tier->getEvent()?->getName() ?? '',
                'quota'     => (int) $tier->getQuota(),
                'available' => $available,
                'currency'  => $tier->getCurrency() ?? 'USD',
                'price'     => $tier->getPrice(),
            ];
        }, $listing->getObjects());

        return new JsonResponse([
            'data'       => $data,
            'page'       => $page,
            'pageSize'   => self::PAGE_SIZE,
            'total'      => $total,
            'totalPages' => (int) ceil($total / self::PAGE_SIZE),
        ]);
    }

    private function serializeOrderDetail(Order $order): array
    {
        $tier  = $order->getTier();
        $event = $tier?->getEvent();
        $venue = $event?->getVenue();

        return [
            'orderNumber'    => $order->getOrderNumber(),
            'email'          => $order->getEmail(),
            'status'         => $order->getStatus(),
            'quantity'       => (int) $order->getQuantity(),
            'totalPrice'     => number_format((float) $order->getTotalPrice(), 2),
            'currency'       => $tier?->getCurrency() ?? 'USD',
            'tierName'       => $tier?->getName() ?? '',
            'eventName'      => $event?->getName() ?? '',
            'eventSlug'      => $event?->getSlug() ?? '',
            'eventStartDate' => $event?->getStartDate()?->format(\DateTimeInterface::ATOM),
            'venueName'      => $venue?->getName() ?? '',
            'createdAt'      => $order->getCreationDate()
                ? (new \DateTime('@' . $order->getCreationDate()))->format(\DateTimeInterface::ATOM)
                : null,
        ];
    }
}
