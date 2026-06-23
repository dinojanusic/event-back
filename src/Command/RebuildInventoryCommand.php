<?php

namespace App\Command;

use Pimcore\Db;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\TicketTier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:inventory:rebuild',
    description: 'Recalculate all tier:*:available Redis counters from quota minus confirmed orders',
)]
class RebuildInventoryCommand extends Command
{
    public function __construct(
        private readonly \Redis $inventoryRedis,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

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

        // Only process published tiers.
        DataObject::setHideUnpublished(true);
        $listing = new TicketTier\Listing();
        $count   = 0;

        foreach ($listing->getObjects() as $tier) {
            $sold      = $soldByTier[$tier->getId()] ?? 0;
            $available = max(0, (int) $tier->getQuota() - $sold);

            $this->inventoryRedis->set(
                sprintf('tier:%d:available', $tier->getId()),
                $available,
            );

            $io->writeln(sprintf(
                '  tier %d (%s): quota=%d  sold=%d  available=%d',
                $tier->getId(),
                $tier->getName(),
                (int) $tier->getQuota(),
                $sold,
                $available,
            ));

            ++$count;
        }

        $io->success(sprintf('Rebuilt inventory for %d tier(s).', $count));

        return Command::SUCCESS;
    }
}
