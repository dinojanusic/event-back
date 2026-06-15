<?php

namespace App\Controller\Api;

use App\Repository\EventRepository;
use App\Repository\TicketTierRepository;
use Pimcore\Model\DataObject\Event;
use Pimcore\Model\DataObject\TicketTier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class EventController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly TicketTierRepository $ticketTierRepository,
    ) {
    }

    #[Route('/api/v1/events', name: 'api_v1_events', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $events = $this->eventRepository->findPublished();

        $data = array_map(fn(Event $event) => [
            'id'        => $event->getId(),
            'name'      => $event->getName(),
            'slug'      => $event->getSlug(),
            'startDate' => $event->getStartDate()?->toDateString(),
            'endDate'   => $event->getEndDate()?->toDateString(),
            'venue'     => $event->getVenue()?->getName(),
            'heroImage' => $this->thumbnailUrl($event),
        ], $events);

        return new JsonResponse($data);
    }

    #[Route('/api/v1/events/{slug}', name: 'api_v1_events_show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $event = $this->eventRepository->findPublishedBySlug($slug);

        if ($event === null) {
            return new JsonResponse(['error' => 'Event not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $tiers = $this->ticketTierRepository->findByEvent($event);

        return new JsonResponse([
            'id'          => $event->getId(),
            'name'        => $event->getName(),
            'slug'        => $event->getSlug(),
            'description' => $event->getDescription(),
            'startDate'   => $event->getStartDate()?->toDateString(),
            'endDate'     => $event->getEndDate()?->toDateString(),
            'venue'       => $event->getVenue()?->getName(),
            'heroImage'   => $this->thumbnailUrl($event),
            'ticketTiers' => array_map(fn(TicketTier $tier) => [
                'id'         => $tier->getId(),
                'name'       => $tier->getName(),
                'price'      => $tier->getPrice(),
                'currency'   => $tier->getCurrency(),
                'quota'      => $tier->getQuota(),
                'salesStart' => $tier->getSalesStart()?->toDateTimeString(),
                'salesEnd'   => $tier->getSalesEnd()?->toDateTimeString(),
            ], $tiers),
        ]);
    }

    private function thumbnailUrl(Event $event): ?string
    {
        $image = $event->getHeroImage();
        if ($image === null) {
            return null;
        }

        return $image->getThumbnail('hero_thumbnail')->getPath();
    }
}
