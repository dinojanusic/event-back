<?php

namespace App\Command;

use Pimcore\Db;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\TicketTier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reservations:reconcile',
    description: 'Credit inventory for TTL-expired reservations and verify tier counters match quota minus confirmed sales',
)]
class ReconcileReservationsCommand extends Command
{

    private const LUA_CLAIM_EXPIRED = <<<'LUA'
local reservation_key = KEYS[1]
local meta_key        = KEYS[2]
if redis.call('EXISTS', reservation_key) == 1 then
    return 0
end
local tierId   = redis.call('HGET', meta_key, 'tierId')
local quantity = redis.call('HGET', meta_key, 'quantity')
if not tierId then return false end
redis.call('DEL', meta_key)
return {tierId, quantity}
LUA;

    public function __construct(
        private readonly \Redis $inventoryRedis,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $now = time();

        $io->section('Phase 1: Releasing expired reservations');
        $this->creditExpiredReservations($io, $now);

        $io->section('Phase 2: Verifying tier counters');
        $driftCount = $this->verifyCounters($io, $now);

        if ($driftCount > 0) {
            $io->warning(sprintf('Fixed counter drift on %d tier(s).', $driftCount));
        } else {
            $io->success('All tier counters are consistent.');
        }

        return Command::SUCCESS;
    }

    private function creditExpiredReservations(SymfonyStyle $io, int $now): void
    {
        $expiredUuids = $this->inventoryRedis->zRangeByScore(
            'reservations:pending',
            '-inf',
            (string) $now,
        );

        if (empty($expiredUuids)) {
            $io->writeln('  No expired reservations to process.');
            return;
        }

        $credited = 0;

        foreach ($expiredUuids as $uuid) {
            $result = $this->inventoryRedis->eval(
                self::LUA_CLAIM_EXPIRED,
                [
                    sprintf('reservation:%s', $uuid),
                    sprintf('reservation:meta:%s', $uuid),
                ],
                2,
            );

            if ($result === 0) {
                continue;
            }

            if ($result === false) {
                $this->inventoryRedis->zRem('reservations:pending', $uuid);
                continue;
            }

            [$tierId, $quantity] = [(int) $result[0], (int) $result[1]];

            $this->inventoryRedis->incrBy(
                sprintf('tier:%d:available', $tierId),
                $quantity,
            );
            $this->inventoryRedis->zRem('reservations:pending', $uuid);

            $this->logger->info('reconciler.reservation.credited', [
                'uuid'     => $uuid,
                'tierId'   => $tierId,
                'quantity' => $quantity,
            ]);

            $io->writeln(sprintf(
                '  [CREDITED] uuid=%.8s…  tier=%d  qty=%d',
                $uuid,
                $tierId,
                $quantity,
            ));

            ++$credited;
        }

        $io->writeln(sprintf('  Credited back %d expired reservation(s).', $credited));
    }

    private function verifyCounters(SymfonyStyle $io, int $now): int
    {
        $holdsByTier = $this->aggregateActiveHolds($now);
        $soldByTier  = $this->fetchConfirmedSales();

        DataObject::setHideUnpublished(true);
        $listing    = new TicketTier\Listing();
        $driftCount = 0;

        foreach ($listing->getObjects() as $tier) {
            $tierId   = $tier->getId();
            $quota    = (int) $tier->getQuota();
            $sold     = $soldByTier[$tierId] ?? 0;
            $holds    = $holdsByTier[$tierId] ?? 0;
            $expected = max(0, $quota - $sold - $holds);
            $actual   = (int) ($this->inventoryRedis->get(sprintf('tier:%d:available', $tierId)) ?: 0);
            $drift    = $expected - $actual;

            if ($drift !== 0) {
                $this->inventoryRedis->set(
                    sprintf('tier:%d:available', $tierId),
                    (string) $expected,
                );

                $this->logger->warning('reconciler.counter.drift_fixed', [
                    'tierId'   => $tierId,
                    'name'     => $tier->getName(),
                    'quota'    => $quota,
                    'sold'     => $sold,
                    'holds'    => $holds,
                    'expected' => $expected,
                    'actual'   => $actual,
                    'drift'    => $drift,
                ]);

                $io->writeln(sprintf(
                    '  [FIXED] tier %d (%s): expected=%d  actual=%d  drift=%+d',
                    $tierId,
                    $tier->getName(),
                    $expected,
                    $actual,
                    $drift,
                ));

                ++$driftCount;
            } else {
                $io->writeln(sprintf(
                    '  [OK]    tier %d (%s): available=%d  (quota=%d  sold=%d  holds=%d)',
                    $tierId,
                    $tier->getName(),
                    $actual,
                    $quota,
                    $sold,
                    $holds,
                ));
            }
        }

        return $driftCount;
    }

    /**
     * Sums in-flight hold quantities per tier from the pending sorted set.
     * Only includes reservations whose expiresAt score is still in the future,
     * so Phase 1's credits are already reflected before this runs.
     *
     * @return array<int, int>  tierId → total held quantity
     */
    private function aggregateActiveHolds(int $now): array
    {
        $activeUuids = $this->inventoryRedis->zRangeByScore(
            'reservations:pending',
            (string) ($now + 1),
            '+inf',
        );

        $holdsByTier = [];

        foreach ($activeUuids as $uuid) {
            $fields = $this->inventoryRedis->hMGet(
                sprintf('reservation:meta:%s', $uuid),
                ['tierId', 'quantity'],
            );

            if ($fields === false || $fields['tierId'] === false) {
                continue;
            }

            $tierId = (int) $fields['tierId'];
            $holdsByTier[$tierId] = ($holdsByTier[$tierId] ?? 0) + (int) $fields['quantity'];
        }

        return $holdsByTier;
    }

    /**
     * @return array<int, int>  tierId → total confirmed quantity (from DB)
     */
    private function fetchConfirmedSales(): array
    {
        $rows = Db::get()->fetchAllAssociative(
            'SELECT r.dest_id AS tier_id, COALESCE(SUM(s.quantity), 0) AS total_sold
             FROM object_relations_order r
             JOIN object_store_order s ON s.oo_id = r.src_id
             JOIN objects o ON o.id = r.src_id AND o.published = 1
             WHERE r.fieldname = ? AND r.ownertype = ? AND r.type = ? AND s.status = ?
             GROUP BY r.dest_id',
            ['tier', 'object', 'object', 'confirmed'],
        );

        $soldByTier = [];
        foreach ($rows as $row) {
            $soldByTier[(int) $row['tier_id']] = (int) $row['total_sold'];
        }

        return $soldByTier;
    }
}
