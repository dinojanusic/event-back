# event-back

Ticket-sales API for the Event platform. Built on **Pimcore 2026 / Symfony 7.4**, with Redis-backed inventory and a crash-safe reservation flow.

---

## Stack

| Layer | Technology |
|---|---|
| Runtime | PHP 8.4 / 8.5 |
| Framework | Symfony 7.4 + Pimcore 2026.1 |
| Database | MariaDB 10.11 (Pimcore object store) |
| Inventory store | Redis (logical DB 2) |
| Cache | Redis (logical DB 0) |
| Rate limiter | Redis (logical DB 3) |
| Async queue | RabbitMQ (Pimcore core jobs) + Redis Stream (order confirmations) |
| Real-time push | Mercure |
| Search | OpenSearch 2 |
| Web server | Nginx (reverse proxy) |
| Process supervisor | Supervisord (inside `supervisord` container) |

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        event-front (Vue 3)                      │
└──────────────────────────────┬──────────────────────────────────┘
                               │ HTTP / JSON
                               ▼
                        ┌─────────────┐
                        │    Nginx    │ :80
                        └──────┬──────┘
                               │
                               ▼
                  ┌────────────────────────┐
                  │  PHP-FPM (Pimcore app) │
                  │                        │
                  │  EventController       │
                  │  ReservationController │◄──── rate limiter (Redis db 3)
                  │  OrderController       │
                  └────────┬───────────────┘
                           │
          ┌────────────────┼──────────────────┐
          ▼                ▼                  ▼
   ┌─────────────┐  ┌───────────┐   ┌────────────────┐
   │  MariaDB    │  │  Redis    │   │   RabbitMQ     │
   │ (Pimcore    │  │ db 0 cache│   │ (Pimcore jobs) │
   │  objects,   │  │ db 2 inv. │   └───────┬────────┘
   │  orders)    │  │ db 3 rl   │           │
   └─────────────┘  └───────────┘           ▼
                                   ┌─────────────────┐
                                   │  Supervisord    │
                                   │  worker         │
                                   │  (messenger:    │
                                   │   consume)      │
                                   └─────────────────┘

  Redis key layout (db 2 — inventory):
  ┌─────────────────────────────────────────────────────┐
  │  tier:{id}:available          (integer counter)     │
  │  reservation:{uuid}           (JSON, TTL 600s)      │
  │  reservation:meta:{uuid}      (hash: tierId, qty)   │
  │  reservations:pending         (sorted set, score=   │
  │                                expiresAt unix ts)   │
  └─────────────────────────────────────────────────────┘
```

---

## Setup

### Prerequisites

- Docker ≥ 24 and Docker Compose v2
- No local PHP or Composer required

### 1. Clone and enter the directory

```bash
cd event-back
```

### 2. Set environment variables

Copy `.env` and edit secrets for your environment:

```bash
cp .env .env.local
```

Key variables:

```dotenv
DATABASE_URL=mysql://pimcore:pimcore@db/pimcore
REDIS_INVENTORY_DSN=redis://redis:6379/2
REDIS_RATE_LIMITER_DSN=redis://redis:6379/3
PIMCORE_MESSENGER_TRANSPORT_DSN_PREFIX=amqp://guest:guest@rabbitmq:5672/%2f/
MERCURE_JWT_KEY=<long-random-string>
APPLICATION_SECRET=<long-random-string>
```

### 3. Start services

```bash
# Set your UID so mounted volumes are writable
sed -i "s|#user: '1000:1000'|user: '$(id -u):$(id -g)'|g" docker-compose.yaml

docker compose up -d
```

### 4. Install Pimcore

```bash
docker compose exec php vendor/bin/pimcore-install \
  --admin-username=admin \
  --admin-password=admin
```

This runs migrations, creates the admin user, and seeds the DB schema.

### 5. Seed inventory counters

After creating TicketTier objects in the Pimcore admin (`/admin`), populate the Redis inventory counters:

```bash
docker compose exec php bin/console app:inventory:rebuild
```

### 6. Verify

- Frontend proxy: http://localhost
- Pimcore admin: http://localhost/admin
- API: http://localhost/api/v1/events

### Running tests

```bash
docker compose run --rm test-php vendor/bin/codecept run -vv
```

---

## API Reference

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/api/v1/events` | — | List published events (cached 60 s) |
| `GET` | `/api/v1/events/{slug}` | — | Event detail + tiers + live inventory |
| `POST` | `/api/v1/reservations` | — | Hold tickets (rate-limited 5/min/IP) |
| `DELETE` | `/api/v1/reservations/{uuid}` | — | Release a hold early |
| `POST` | `/api/v1/orders` | — | Convert a hold into a confirmed order |

### POST /api/v1/reservations

```json
// Request
{ "tierId": 42, "quantity": 2 }

// 201 Created
{ "uuid": "018f…", "expiresAt": 1718000000 }

// 409 Conflict — sold out
{ "error": "Not enough tickets available" }

// 429 Too Many Requests
{ "error": "Too many requests. Please slow down." }
```

### POST /api/v1/orders

```json
// Request
{ "reservationUuid": "018f…", "email": "buyer@example.com" }

// 201 Created
{ "orderNumber": "ORD-1A2B3C4D5E" }

// 410 Gone — reservation expired or already consumed
{ "error": "Reservation has expired or does not exist. Please reserve again." }
```

---

## Reservation Concurrency Design

The core challenge: hundreds of concurrent buyers attempting to purchase the same limited pool of tickets without overselling.

### Inventory as a Redis counter

Each `TicketTier` has one Redis key: `tier:{id}:available`. This integer is the single source of truth for remaining tickets. Reads for the event-detail page pull this counter directly from Redis (bypassing the 60 s HTTP cache) so buyers always see a live number.

### Atomic decrement via Lua

`POST /api/v1/reservations` must atomically **check available ≥ quantity and decrement** in a single operation. Two concurrent requests for the last ticket must not both succeed.

A Lua script executed with `EVAL` handles this in one Redis round-trip:

```lua
local cur = tonumber(redis.call('GET', KEYS[1]))
if cur == nil or cur < tonumber(ARGV[1]) then return 0 end
redis.call('DECRBY', KEYS[1], ARGV[1])
return 1
```

Redis runs Lua scripts atomically — no two scripts execute concurrently on the same server — which eliminates the check-then-act race without needing a distributed lock.

### The reservation record

After a successful decrement, `ReservationService::reserve()` writes three Redis structures:

| Key | Type | Purpose |
|---|---|---|
| `reservation:{uuid}` | String (JSON), TTL 600 s | Authorization token for the purchase |
| `reservation:meta:{uuid}` | Hash (tierId, qty) | Metadata for the reconciler |
| `reservations:pending` | Sorted set, score = expiresAt | Index for expiry scanning |

The 10-minute TTL on the main key is the primary expiry mechanism — Redis evicts it automatically. No application-level timer is needed.

### Idempotent release via Lua

Both `release()` (buyer cancels) and `consume()` (buyer completes order) use the same Lua script:

```lua
local val = redis.call('GET', KEYS[1])
if not val then return false end
redis.call('DEL', KEYS[1])
return val
```

GET + DEL in one atomic operation means only the first caller gets a non-false return value. All subsequent calls are no-ops. This makes both operations safe to retry without side effects.

The difference is what happens next:
- `release()` receives the payload → credits inventory back with `INCRBY`
- `consume()` receives the payload → does **not** credit inventory (sale is confirmed)

### Rate limiting

`POST /api/v1/reservations` is protected by a Redis-backed sliding-window rate limiter: **5 requests per IP per minute**. This prevents a single client from holding all inventory in contested scenarios.

---

## Crash Safety: the consume-first pattern

The naive order-creation flow is:

```
validate key → save Order to DB → delete key
```

This is dangerous: if the process crashes after saving the order but before deleting the key, the key is still valid. A client retry or network re-delivery creates a **duplicate order and a duplicate charge**.

`OrderService::placeOrder()` reverses the sequence:

```
consume key (atomic GET+DEL) → save Order to DB
```

The crash scenarios are now:

| Crash point | State after crash | Retry result |
|---|---|---|
| Before consume | Key still alive | Retry succeeds normally |
| After consume, before DB write | Key gone, no Order in DB | Retry gets 410 Gone — must re-reserve |
| After DB write | Order exists, key gone | Success already recorded |

The trade-off: a crash in the second scenario loses the sale for that attempt. The inventory counter remains decremented until the reconciler's next run restores it. That is far preferable to silently charging a customer twice.

---

## Recovery Strategy: the reconciler

Redis TTLs expire passively — there is no callback to credit inventory back. Without intervention, a wave of expired reservations leaves the counter permanently low.

`app:reservations:reconcile` corrects this. It runs hourly via Supervisord and executes two phases.

### Phase 1 — Credit expired reservations

```
zRangeByScore reservations:pending -inf <now>
  for each uuid:
    EVAL LUA_CLAIM_EXPIRED reservation:{uuid} reservation:meta:{uuid}
      → 0      : main key still alive (TTL slightly ahead of wall clock) — skip
      → false  : meta already gone (consumed, released, or claimed) — remove from index
      → [tierId, qty] : claimed — INCRBY tier:{tierId}:available qty
```

`LUA_CLAIM_EXPIRED` is atomic: if two reconciler processes run simultaneously, only one can claim the metadata and credit inventory. The other sees `false` and skips.

### Phase 2 — Verify counters

For every `TicketTier`:

```
expected = max(0, quota − confirmed_sales − active_holds)
actual   = GET tier:{id}:available

if expected ≠ actual:
    SET tier:{id}:available expected
    log warning with full diff
```

`confirmed_sales` comes from a direct MariaDB query; `active_holds` is summed from the `reservations:pending` sorted set (future scores only). Any counter drift — caused by a crash mid-reservation, Redis persistence failure, or any other edge case — is detected and corrected here.

### Cron schedule

Add to crontab or system scheduler:

```bash
# Hourly reconciliation
0 * * * * docker compose exec -T php bin/console app:reservations:reconcile >> /var/log/reconcile.log 2>&1
```

---

## Project Structure

```
src/
├── Controller/Api/
│   ├── EventController.php        # GET /api/v1/events[/{slug}]
│   ├── ReservationController.php  # POST/DELETE /api/v1/reservations
│   └── OrderController.php        # POST /api/v1/orders
├── Service/
│   ├── ReservationService.php     # Lua scripts, Redis key management
│   └── OrderService.php           # consume-first order creation
├── Command/
│   └── ReconcileReservationsCommand.php
├── Messenger/Message/
│   └── SendOrderConfirmation.php  # Async email job
├── Repository/
│   ├── EventRepository.php
│   └── TicketTierRepository.php
└── Exception/
    ├── InsufficientInventoryException.php
    └── ReservationExpiredException.php

var/classes/DataObject/
├── Event.php       # Pimcore-generated model
├── TicketTier.php
└── Order.php
```
