<?php

namespace App\EventSubscriber;

use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\TicketTier;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class InventorySeeder implements EventSubscriberInterface
{
    /** @var array<int, bool> tier IDs whose persisted state was unpublished before the current save */
    private array $wasUnpublished = [];

    public function __construct(
        private readonly \Redis $inventoryRedis,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            DataObjectEvents::PRE_UPDATE  => 'onPreUpdate',
            DataObjectEvents::POST_UPDATE => 'onPostUpdate',
            DataObjectEvents::POST_ADD    => 'onPostAdd',
        ];
    }

    public function onPreUpdate(DataObjectEvent $event): void
    {
        $object = $event->getObject();
        if (!$object instanceof TicketTier || !$object->getId()) {
            return;
        }

        $published = (bool) \Pimcore\Db::get()->fetchOne(
            'SELECT published FROM objects WHERE id = ?',
            [$object->getId()],
        );

        $this->wasUnpublished[$object->getId()] = !$published;
    }

    public function onPostUpdate(DataObjectEvent $event): void
    {
        $object = $event->getObject();
        if (!$object instanceof TicketTier) {
            return;
        }

        $id             = $object->getId();
        $justPublished  = ($this->wasUnpublished[$id] ?? false) && $object->isPublished();
        unset($this->wasUnpublished[$id]);

        if ($justPublished) {
            $this->seed($object);
        }
    }

    public function onPostAdd(DataObjectEvent $event): void
    {
        $object = $event->getObject();
        if ($object instanceof TicketTier && $object->isPublished()) {
            $this->seed($object);
        }
    }

    private function seed(TicketTier $tier): void
    {
        $this->inventoryRedis->set(
            sprintf('tier:%d:available', $tier->getId()),
            (int) $tier->getQuota(),
        );
    }
}
