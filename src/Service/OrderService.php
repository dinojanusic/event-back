<?php

namespace App\Service;

use App\Exception\OrderAlreadyCancelledException;
use App\Exception\OrderNotFoundException;
use App\Exception\ReservationExpiredException;
use App\Messenger\Message\SendOrderConfirmation;
use Pimcore\Model\DataObject\Order;
use Pimcore\Model\DataObject\Service as DataObjectService;
use Pimcore\Model\DataObject\TicketTier;
use Symfony\Component\Messenger\MessageBusInterface;

class OrderService
{
    public function __construct(
        private readonly ReservationService $reservationService,
        private readonly MessageBusInterface $bus,
        private readonly \Redis $inventoryRedis,
    ) {}

    /**
     * Converts a live reservation into a confirmed Order and returns its order number.
     *
     * CONSUME-THE-KEY PATTERN
     * -----------------------
     * The reservation UUID is the single-use authorisation token for this purchase.
     * We atomically GET+DELETE it from Redis *before* writing anything to the database.
     * This gives at-most-once semantics for order creation:
     *
     *   • Key missing (expired TTL, already consumed, or never existed) → throw
     *     ReservationExpiredException. A retry cannot succeed, so no duplicate is possible.
     *
     *   • Key present → deleted atomically, then the Order is saved to Pimcore.
     *
     * CRASH WINDOW (save-first ordering and why we avoid it)
     * -------------------------------------------------------
     * The naive sequence is: validate key → save Order → delete key.
     * This creates a dangerous window:
     *
     *   1. Order saved to MySQL  ✓
     *   2. [CRASH / OOM / deploy]
     *   3. Redis key still alive  ← appears valid to any retry
     *   4. Client retries on 5xx → Order created again → DUPLICATE CHARGE
     *
     * By consuming the key first (delete → save) the window becomes:
     *
     *   1. Key atomically consumed from Redis  ✓
     *   2. [CRASH]
     *   3. No Order in DB, no key in Redis
     *   4. Client retries → 410 Gone → must re-reserve → no duplicate
     *
     * Trade-off: a crash after consumption but before the DB write loses this
     * particular sale (the inventory counter stays decremented until the next
     * rebuild). That is far safer than silently double-charging a customer.
     *
     * @throws ReservationExpiredException if the reservation key no longer exists
     */
    public function placeOrder(string $reservationUuid, string $email): string
    {
        $data = $this->reservationService->consume($reservationUuid);
        if ($data === false) {
            throw new ReservationExpiredException($reservationUuid);
        }

        /** @var TicketTier $tier */
        $tier = TicketTier::getById($data['tierId']);

        $totalPrice  = (float) $tier->getPrice() * $data['quantity'];
        $orderNumber = $this->generateOrderNumber();

        $order = new Order();
        $order->setKey($orderNumber);
        $order->setParent(DataObjectService::createFolderByPath('/orders'));
        $order->setPublished(true);
        $order->setOrderNumber($orderNumber);
        $order->setEmail($email);
        $order->setStatus('confirmed');
        $order->setTier($tier);
        $order->setQuantity($data['quantity']);
        $order->setTotalPrice((string) $totalPrice);
        $order->save();

        $event = $tier->getEvent();
        $this->bus->dispatch(new SendOrderConfirmation(
            orderNumber: $orderNumber,
            email: $email,
            tierName: $tier->getName() ?? '',
            eventName: $event?->getName() ?? '',
            eventStartDate: $event?->getStartDate(),
            venueName: $event?->getVenue()?->getName() ?? '',
            quantity: $data['quantity'],
            totalPrice: number_format($totalPrice, 2),
            currency: $tier->getCurrency() ?? 'USD',
        ));

        return $orderNumber;
    }

    /**
     * Cancels a confirmed order and credits inventory back.
     *
     * The reservation key was atomically consumed in placeOrder(), so there is no
     * Redis reservation to release. Inventory is credited directly via INCRBY,
     * mirroring the logic in ReservationService::release().
     *
     * @throws OrderNotFoundException         if the order does not exist
     * @throws OrderAlreadyCancelledException if the order is already cancelled
     */
    public function cancelOrder(string $orderNumber): Order
    {
        /** @var Order|null $order */
        $order = Order::getByOrderNumber($orderNumber, 1);

        if ($order === null) {
            throw new OrderNotFoundException($orderNumber);
        }

        if ($order->getStatus() === 'cancelled') {
            throw new OrderAlreadyCancelledException($orderNumber);
        }

        $order->setStatus('cancelled');
        $order->save();

        $tier     = $order->getTier();
        $quantity = (int) $order->getQuantity();

        if ($tier !== null && $quantity > 0) {
            $this->inventoryRedis->incrBy(
                sprintf('tier:%d:available', $tier->getId()),
                $quantity,
            );
        }

        return $order;
    }

    private function generateOrderNumber(): string
    {
        return sprintf('ORD-%s', strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)));
    }
}
