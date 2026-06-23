<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class CorsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $corsAllowOrigin,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onRequest', 9999],
            KernelEvents::RESPONSE => ['onResponse', 9999],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        // Handle preflight OPTIONS requests immediately — no further processing needed.
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response('', Response::HTTP_NO_CONTENT);
            $this->addCorsHeaders($request->headers->get('Origin', ''), $response);
            $event->setResponse($response);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $this->addCorsHeaders($request->headers->get('Origin', ''), $event->getResponse());
    }

    private function addCorsHeaders(string $origin, Response $response): void
    {
        $allowedOrigin = $this->corsAllowOrigin === '*'
            ? '*'
            : (str_contains($this->corsAllowOrigin, $origin) ? $origin : $this->corsAllowOrigin);

        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Admin-Key');
        $response->headers->set('Access-Control-Max-Age', '3600');
    }
}
