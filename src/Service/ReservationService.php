<?php

namespace App\Service;

use App\Exception\InsufficientInventoryException;
use Symfony\Component\Uid\Uuid;

class ReservationService
{
    private const RESERVATION_TTL = 600; // 10 minutes

    /**
     * Atomically checks available >= quantity and decrements in one Redis round-trip.
     * Returns 1 on success, 0 if stock is insufficient.
     */
    private const LUA_DECREMENT = <<<'LUA'
local cur = tonumber(redis.call('GET', KEYS[1]))
if cur == nil or cur < tonumber(ARGV[1]) then
    return 0
end
redis.call('DECRBY', KEYS[1], ARGV[1])
return 1
LUA;

    /**
     * Atomically reads and deletes the reservation key in one round-trip.
     * Returns the JSON payload if the key existed, false if it was already gone.
     * This makes release idempotent: only the first caller gets a non-false return
     * and credits the inventory counter.
     */
    private const LUA_RELEASE = <<<'LUA'
local val = redis.call('GET', KEYS[1])
if not val then return false end
redis.call('DEL', KEYS[1])
return val
LUA;

    public function __construct(
        private readonly \Redis $inventoryRedis,
    ) {}

    /**
     * @return array{uuid: string, expiresAt: int}
     * @throws InsufficientInventoryException
     */
    public function reserve(int $tierId, int $quantity): array
    {
        $inventoryKey = sprintf('tier:%d:available', $tierId);

        $decremented = $this->inventoryRedis->eval(
            self::LUA_DECREMENT,
            [$inventoryKey, (string) $quantity],
            1,
        );

        if ($decremented !== 1) {
            throw new InsufficientInventoryException($tierId, $quantity);
        }

        $uuid      = Uuid::v4()->toRfc4122();
        $expiresAt = time() + self::RESERVATION_TTL;

        $this->inventoryRedis->setex(
            sprintf('reservation:%s', $uuid),
            self::RESERVATION_TTL,
            json_encode([
                'tierId'    => $tierId,
                'quantity'  => $quantity,
                'expiresAt' => $expiresAt,
            ], JSON_THROW_ON_ERROR),
        );

        $this->inventoryRedis->zAdd('reservations:pending', (float) $expiresAt, $uuid);
        $this->inventoryRedis->hMSet(sprintf('reservation:meta:%s', $uuid), [
            'tierId'   => $tierId,
            'quantity' => $quantity,
        ]);

        return ['uuid' => $uuid, 'expiresAt' => $expiresAt];
    }

    /**
     * Consumes a reservation for order fulfilment. Atomically deletes the key
     * and returns its payload — but does NOT credit inventory back, because the
     * decrement now corresponds to a confirmed sale.
     *
     * Returns the decoded payload on success, false if the key is already gone.
     * Idempotent: a second call for the same UUID always returns false.
     */
    public function consume(string $uuid): array|false
    {
        $reservationKey = sprintf('reservation:%s', $uuid);

        $payload = $this->inventoryRedis->eval(
            self::LUA_RELEASE,
            [$reservationKey],
            1,
        );

        if ($payload === false) {
            return false;
        }

        // Clean up secondary index so the reconciler ignores this UUID.
        // Non-critical: a missed cleanup is harmless — the reconciler's Lua
        // claim script will detect meta already gone and skip it.
        $this->inventoryRedis->zRem('reservations:pending', $uuid);
        $this->inventoryRedis->del(sprintf('reservation:meta:%s', $uuid));

        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Releases a hold early. Idempotent: safe to call multiple times for the same UUID.
     *
     * Returns true if the reservation existed and inventory was credited back,
     * false if it was already gone (expired or previously released).
     */
    public function release(string $uuid): bool
    {
        $reservationKey = sprintf('reservation:%s', $uuid);

        $payload = $this->inventoryRedis->eval(
            self::LUA_RELEASE,
            [$reservationKey],
            1,
        );

        if ($payload === false) {
            return false;
        }

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        $this->inventoryRedis->incrBy(
            sprintf('tier:%d:available', $data['tierId']),
            $data['quantity'],
        );

        $this->inventoryRedis->zRem('reservations:pending', $uuid);
        $this->inventoryRedis->del(sprintf('reservation:meta:%s', $uuid));

        return true;
    }
}
