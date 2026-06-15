<?php

namespace App\Command;

use Carbon\Carbon;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Event;
use Pimcore\Model\DataObject\Folder;
use Pimcore\Model\DataObject\TicketTier;
use Pimcore\Model\DataObject\Venue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:fixtures:load', description: 'Seed venues, events, and ticket tiers')]
class LoadFixturesCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Delete existing fixture objects before seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        DataObject::setHideUnpublished(false);

        $venueFolder = $this->ensureFolder('Venues');
        $eventFolder = $this->ensureFolder('Events');
        $tierFolder  = $this->ensureFolder('TicketTiers');

        if ($input->getOption('purge')) {
            $this->purgeFolder($venueFolder, $io);
            $this->purgeFolder($eventFolder, $io);
            $this->purgeFolder($tierFolder, $io);
        }

        [$venue1, $venue2] = $this->seedVenues($venueFolder, $io);
        $this->seedEventsWithTiers($eventFolder, $tierFolder, $venue1, $venue2, $io);

        $io->success('Fixtures loaded.');

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Venues
    // -------------------------------------------------------------------------

    private function seedVenues(Folder $parent, SymfonyStyle $io): array
    {
        $venues = [
            [
                'key'      => 'grand-arena',
                'name'     => 'Grand Arena',
                'address'  => "1 Championship Blvd\nNew York, NY 10001",
                'capacity' => 20000,
            ],
            [
                'key'      => 'riverside-hall',
                'name'     => 'Riverside Hall',
                'address'  => "42 River Street\nLondon, EC1A 1BB",
                'capacity' => 3500,
            ],
        ];

        $created = [];

        foreach ($venues as $data) {
            $venue = new Venue();
            $venue->setParent($parent);
            $venue->setKey($data['key']);
            $venue->setPublished(true);

            $venue->setName($data['name']);
            $venue->setAddress($data['address']);
            $venue->setCapacity($data['capacity']);

            $venue->save();

            $io->writeln(sprintf('  [venue] %s (id %d)', $venue->getName(), $venue->getId()));
            $created[] = $venue;
        }

        return $created;
    }

    // -------------------------------------------------------------------------
    // Events + ticket tiers
    // -------------------------------------------------------------------------

    private function seedEventsWithTiers(
        Folder $eventFolder,
        Folder $tierFolder,
        Venue $venue1,
        Venue $venue2,
        SymfonyStyle $io,
    ): void {
        $fixtures = [
            [
                'key'         => 'summer-beats-2026',
                'name'        => 'Summer Beats 2026',
                'slug'        => 'summer-beats-2026',
                'description' => 'The biggest outdoor music festival of the year.',
                'startDate'   => '2026-07-15',
                'endDate'     => '2026-07-17',
                'venue'       => $venue1,
                'status'      => 'published',
                'tiers'       => [
                    ['name' => 'Early Bird',  'price' => 49.00,  'quota' => 500,  'salesStart' => '2026-01-01 00:00', 'salesEnd' => '2026-03-31 23:59'],
                    ['name' => 'General',     'price' => 89.00,  'quota' => 2000, 'salesStart' => '2026-04-01 00:00', 'salesEnd' => '2026-07-14 23:59'],
                    ['name' => 'VIP',         'price' => 249.00, 'quota' => 100,  'salesStart' => '2026-01-01 00:00', 'salesEnd' => '2026-07-14 23:59'],
                ],
            ],
            [
                'key'         => 'jazz-in-the-park',
                'name'        => 'Jazz in the Park',
                'slug'        => 'jazz-in-the-park',
                'description' => 'An intimate evening of classic and contemporary jazz.',
                'startDate'   => '2026-08-22',
                'endDate'     => '2026-08-22',
                'venue'       => $venue2,
                'status'      => 'published',
                'tiers'       => [
                    ['name' => 'Standard',  'price' => 35.00, 'quota' => 800, 'salesStart' => '2026-02-01 00:00', 'salesEnd' => '2026-08-21 23:59'],
                    ['name' => 'Premium',   'price' => 75.00, 'quota' => 200, 'salesStart' => '2026-02-01 00:00', 'salesEnd' => '2026-08-21 23:59'],
                ],
            ],
            [
                'key'         => 'tech-summit-autumn',
                'name'        => 'Tech Summit Autumn',
                'slug'        => 'tech-summit-autumn',
                'description' => 'Two days of keynotes, workshops, and networking.',
                'startDate'   => '2026-10-05',
                'endDate'     => '2026-10-06',
                'venue'       => $venue1,
                'status'      => 'published',
                'tiers'       => [
                    ['name' => 'Community', 'price' => 0.00,   'quota' => 300,  'salesStart' => '2026-05-01 00:00', 'salesEnd' => '2026-10-04 23:59'],
                    ['name' => 'Developer', 'price' => 199.00, 'quota' => 1000, 'salesStart' => '2026-05-01 00:00', 'salesEnd' => '2026-10-04 23:59'],
                    ['name' => 'Corporate', 'price' => 499.00, 'quota' => 250,  'salesStart' => '2026-05-01 00:00', 'salesEnd' => '2026-10-04 23:59'],
                ],
            ],
            [
                'key'         => 'winter-wonderland-gala',
                'name'        => 'Winter Wonderland Gala',
                'slug'        => 'winter-wonderland-gala',
                'description' => 'A black-tie charity gala with live orchestra.',
                'startDate'   => '2026-12-19',
                'endDate'     => '2026-12-19',
                'venue'       => $venue2,
                'status'      => 'published',
                'tiers'       => [
                    ['name' => 'Table of 2',  'price' => 300.00, 'quota' => 80, 'salesStart' => '2026-09-01 00:00', 'salesEnd' => '2026-12-18 23:59'],
                    ['name' => 'Table of 10', 'price' => 1200.00, 'quota' => 20, 'salesStart' => '2026-09-01 00:00', 'salesEnd' => '2026-12-18 23:59'],
                    ['name' => 'Patron',      'price' => 2500.00, 'quota' => 5,  'salesStart' => '2026-09-01 00:00', 'salesEnd' => '2026-12-18 23:59'],
                ],
            ],
        ];

        foreach ($fixtures as $data) {
            $event = new Event();
            $event->setParent($eventFolder);
            $event->setKey($data['key']);
            $event->setPublished(true);

            $event->setName($data['name']);
            $event->setSlug($data['slug']);
            $event->setDescription($data['description']);
            $event->setStartDate(Carbon::parse($data['startDate']));
            $event->setEndDate(Carbon::parse($data['endDate']));
            $event->setVenue($data['venue']);
            $event->setStatus($data['status']);

            $event->save();

            $io->writeln(sprintf('  [event] %s (id %d)', $event->getName(), $event->getId()));

            foreach ($data['tiers'] as $tierData) {
                $tier = new TicketTier();
                $tier->setParent($tierFolder);
                $tier->setKey(sprintf('%s-%s', $data['key'], strtolower(str_replace(' ', '-', $tierData['name']))));
                $tier->setPublished(true);

                $tier->setName($tierData['name']);
                $tier->setPrice($tierData['price']);
                $tier->setCurrency('EUR');
                $tier->setQuota($tierData['quota']);
                $tier->setSalesStart(Carbon::parse($tierData['salesStart']));
                $tier->setSalesEnd(Carbon::parse($tierData['salesEnd']));
                $tier->setEvent($event);

                $tier->save();

                $io->writeln(sprintf('    [tier] %s — €%.2f (quota %d)', $tier->getName(), $tier->getPrice(), $tier->getQuota()));
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function ensureFolder(string $key): Folder
    {
        $path = '/' . $key;
        $folder = Folder::getByPath($path);

        if (!$folder instanceof Folder) {
            $folder = new Folder();
            $folder->setParent(DataObject::getById(1)); // root
            $folder->setKey($key);
            $folder->save();
        }

        return $folder;
    }

    private function purgeFolder(Folder $folder, SymfonyStyle $io): void
    {
        foreach ($folder->getChildren() as $child) {
            $io->writeln(sprintf('  [purge] %s', $child->getFullPath()));
            $child->delete();
        }
    }
}
