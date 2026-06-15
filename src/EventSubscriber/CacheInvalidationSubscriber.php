<?php

namespace App\EventSubscriber;

use Pimcore\Event\DataObjectEvents;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Event;
use Pimcore\Model\DataObject\TicketTier;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class CacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TagAwareCacheInterface $apiCache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DataObjectEvents::POST_ADD    => 'onObjectSaved',
            DataObjectEvents::POST_UPDATE => 'onObjectSaved',
            DataObjectEvents::POST_DELETE => 'onObjectSaved',
        ];
    }

    public function onObjectSaved(DataObjectEvent $pimcoreEvent): void
    {
        $object = $pimcoreEvent->getObject();

        if ($object instanceof Event) {
            $tags = ['api_events_list'];
            if ($slug = $object->getSlug()) {
                $tags[] = 'api_event_' . $slug;
            }
            $this->apiCache->invalidateTags($tags);
            return;
        }

        if ($object instanceof TicketTier) {
            $relatedEvent = $object->getEvent();
            if ($relatedEvent instanceof Event && ($slug = $relatedEvent->getSlug())) {
                $this->apiCache->invalidateTags(['api_event_' . $slug]);
            }
        }
    }
}
