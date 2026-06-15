<?php

namespace App\Repository;

use Pimcore\Model\DataObject\Event;

class EventRepository
{
    public function findPublished(): array
    {
        $listing = new Event\Listing();
        $listing->filterByStatus('published');
        $listing->setOrderKey('startDate');
        $listing->setOrder('ASC');

        return $listing->getObjects();
    }

    public function findPublishedBySlug(string $slug): ?Event
    {
        $listing = new Event\Listing();
        $listing->filterBySlug($slug);
        $listing->filterByStatus('published');
        $listing->setLimit(1);

        return $listing->current() ?: null;
    }
}
