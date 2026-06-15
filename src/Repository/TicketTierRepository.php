<?php

namespace App\Repository;

use Pimcore\Model\DataObject\Event;
use Pimcore\Model\DataObject\TicketTier;

class TicketTierRepository
{
    public function findByEvent(Event $event): array
    {
        $listing = new TicketTier\Listing();
        $listing->filterByEvent($event);
        $listing->setOrderKey('price');
        $listing->setOrder('ASC');

        return $listing->getObjects();
    }
}
