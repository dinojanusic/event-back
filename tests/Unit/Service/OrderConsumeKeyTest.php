<?php

namespace App\Tests\Unit\Service;

use App\Service\ReservationService;
use Codeception\Test\Unit;

/**
 * Proves the consume-the-key contract used by OrderService::placeOrder().
 *
 * WHAT WE'RE TESTING
 * ------------------
 * ReservationService::consume() is the atomic primitive that makes
 * at-most-once order creation possible. These tests verify:
 *
 *   1. A present key is returned and destroyed in one call (idempotency).
 *   2. A missing / expired key returns false (the 410 case).
 *   3. consume() never credits inventory back — unlike release(), the
 *      inventory decrement persists as a confirmed sale.
 *
 * CRASH WINDOW — WHAT THE TEST DOES NOT (AND CANNOT) PROVE
 * ---------------------------------------------------------
 * The safety of the consume-first ordering is an architectural property,
 * not something a unit test can demonstrate directly. The reasoning:
 *
 *   consume-first (what we do):
 *     Step 1 → DELETE reservation key from Redis   ← atomic, durable
 *     Step 2 → INSERT Order row in MySQL
 *
 *   If the process crashes between step 1 and step 2:
 *     • Redis key is gone   → client retries → 410 Gone → must re-reserve
 *     • No Order in MySQL   → no duplicate charge, no phantom row
 *
 *   save-first (naive alternative, DO NOT USE):
 *     Step 1 → INSERT Order row in MySQL
 *     Step 2 → DELETE reservation key from Redis
 *
 *   If the process crashes between step 1 and step 2:
 *     • Order row EXISTS in MySQL
 *     • Redis key still present → client retries → second Order row created
 *     → DUPLICATE CHARGE
 *
 * The unit tests below confirm the building block is correct; the ordering
 * choice is enforced by the structure of OrderService::placeOrder().
 */
class OrderConsumeKeyTest extends Unit
{
    private const TIER_ID  = 42;
    private const QUANTITY = 2;

    // -------------------------------------------------------------------------
    // Stub
    // -------------------------------------------------------------------------

    /**
     * Minimal \Redis stub that faithfully implements LUA_DECREMENT and
     * LUA_RELEASE semantics using plain PHP state.
     */
    private function makeRedis(int $quota): array
    {
        $storage = [sprintf('tier:%d:available', self::TIER_ID) => $quota];
        $redis   = $this->createMock(\Redis::class);

        $redis->method('eval')->willReturnCallback(
            function (string $script, array $keys, int $numKeys) use (&$storage): mixed {
                $key = $keys[0];

                if (str_contains($script, 'DECRBY')) {
                    $quantity = (int) ($keys[1] ?? 1);
                    $cur      = $storage[$key] ?? null;
                    if ($cur === null || $cur < $quantity) {
                        return 0;
                    }
                    $storage[$key] -= $quantity;
                    return 1;
                }

                if (str_contains($script, 'DEL')) {
                    if (!array_key_exists($key, $storage)) {
                        return false;
                    }
                    $val = $storage[$key];
                    unset($storage[$key]);
                    return $val;
                }

                return false;
            }
        );

        $redis->method('setex')->willReturnCallback(
            function (string $key, int $ttl, string $value) use (&$storage): bool {
                $storage[$key] = $value;
                return true;
            }
        );

        $redis->method('incrBy')->willReturnCallback(
            function (string $key, int $amount) use (&$storage): int {
                $storage[$key] = ($storage[$key] ?? 0) + $amount;
                return (int) $storage[$key];
            }
        );

        return [$redis, &$storage];
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * A successful consume() returns the reservation payload and removes the key.
     * A second call for the same UUID returns false — the token is single-use.
     */
    public function testConsumeReturnsPayloadAndDestroysKey(): void
    {
        [$redis] = $this->makeRedis(self::QUANTITY);
        $service = new ReservationService($redis);

        $result = $service->reserve(self::TIER_ID, self::QUANTITY);
        $uuid   = $result['uuid'];

        $payload = $service->consume($uuid);

        self::assertIsArray($payload, 'consume() must return an array for a live reservation');
        self::assertSame(self::TIER_ID, $payload['tierId']);
        self::assertSame(self::QUANTITY, $payload['quantity']);

        // Second call — key is gone, token is single-use
        $second = $service->consume($uuid);
        self::assertFalse($second, 'consume() must return false when the key no longer exists');
    }

    /**
     * Consuming a key that never existed (or already expired) returns false,
     * which maps to 410 Gone at the HTTP layer.
     */
    public function testConsumeReturnsFalseForMissingKey(): void
    {
        [$redis] = $this->makeRedis(self::QUANTITY);
        $service = new ReservationService($redis);

        $result = $service->consume('00000000-0000-0000-0000-000000000000');

        self::assertFalse($result);
    }

    /**
     * Unlike release(), consume() must NOT credit inventory back.
     * After consume() the tier counter stays decremented — it now represents
     * a confirmed sale. Crediting it back would make that ticket available
     * for a second buyer.
     */
    public function testConsumeDoesNotCreditInventoryBack(): void
    {
        [$redis, &$storage] = $this->makeRedis(self::QUANTITY);
        $service = new ReservationService($redis);

        $result = $service->reserve(self::TIER_ID, self::QUANTITY);

        $counterAfterReserve = $storage[sprintf('tier:%d:available', self::TIER_ID)];
        self::assertSame(0, $counterAfterReserve, 'Reserve must decrement the counter');

        $service->consume($result['uuid']);

        $counterAfterConsume = $storage[sprintf('tier:%d:available', self::TIER_ID)];
        self::assertSame(
            0,
            $counterAfterConsume,
            'consume() must not credit inventory back — the decrement is a confirmed sale',
        );
    }

    /**
     * release() credits inventory back (early cancellation path).
     * This test is the counterpart to testConsumeDoesNotCreditInventoryBack()
     * and makes the behavioural difference between the two methods explicit.
     */
    public function testReleaseCreditsInventoryBack(): void
    {
        [$redis, &$storage] = $this->makeRedis(self::QUANTITY);
        $service = new ReservationService($redis);

        $result = $service->reserve(self::TIER_ID, self::QUANTITY);

        $service->release($result['uuid']);

        $counterAfterRelease = $storage[sprintf('tier:%d:available', self::TIER_ID)];
        self::assertSame(
            self::QUANTITY,
            $counterAfterRelease,
            'release() must restore the inventory counter',
        );
    }
}
