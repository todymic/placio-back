<?php

namespace App\Controller;

use App\Service\EventService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/public')]
class PublicEventController extends AbstractController
{
    public function __construct(
        private EventService $eventService,
    ) {
    }

    #[Route('/events/{eventId}', methods: ['GET'])]
    public function show(string $eventId): JsonResponse
    {
        // This endpoint accepts session tokens for public access
        $event = $this->eventService->findById($eventId);
        return $this->json($event);
    }
}

