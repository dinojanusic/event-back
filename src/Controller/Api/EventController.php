<?php

namespace App\Controller\Api;

use App\Repository\EventRepository;
use App\Repository\TicketTierRepository;
use Pimcore\Model\DataObject\Event;
use Pimcore\Model\DataObject\TicketTier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class EventController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly TicketTierRepository $ticketTierRepository,
        private readonly TagAwareCacheInterface $apiCache,
        private readonly \Redis $inventoryRedis,
    ) {
    }

    #[Route('/api/v1/events', name: 'api_v1_events', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $data = $this->apiCache->get('api.events.list', function (ItemInterface $item): array {
            $item->tag(['api_events_list']);
            $item->expiresAfter(60);

            $events = $this->eventRepository->findPublished();

            return array_map(fn(Event $event) => [
                'id'        => $event->getId(),
                'name'      => $event->getName(),
                'slug'      => $event->getSlug(),
                'startDate' => $event->getStartDate()?->toDateString(),
                'endDate'   => $event->getEndDate()?->toDateString(),
                'venue'     => $event->getVenue()?->getName(),
                'heroImage' => $this->thumbnailUrl($event),
            ], $events);
        });

        return new JsonResponse($data);
    }

    #[Route('/api/v1/events/{slug}', name: 'api_v1_events_show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $data = $this->apiCache->get('api.event.' . $slug, function (ItemInterface $item) use ($slug): ?array {
            $item->tag(['api_event_' . $slug]);
            $item->expiresAfter(60);

            $event = $this->eventRepository->findPublishedBySlug($slug);
            if ($event === null) {
                return null;
            }

            $tiers = $this->ticketTierRepository->findByEvent($event);

            return [
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
            ];
        });

        if ($data === null) {
            return new JsonResponse(['error' => 'Event not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        // Merge live Redis counters outside the cache — these change on every reservation.
        foreach ($data['ticketTiers'] as &$tier) {
            $raw = $this->inventoryRedis->get(sprintf('tier:%d:available', $tier['id']));
            $tier['available'] = $raw !== false ? (int) $raw : null;
        }
        unset($tier);

        return new JsonResponse($data);
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
