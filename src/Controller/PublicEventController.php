<?php

namespace App\Controller;

use App\Service\EventService;
use OpenApi\Attributes as OA;
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
    #[OA\Tag(name: 'Public')]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Security(name: 'Bearer')]
    #[OA\Security(name: 'ApiKeyId')]
    #[OA\Security(name: 'ApiKeySecret')]
    #[OA\Response(response: 200, description: 'Evenement detaille')]
    #[OA\Response(response: 404, description: 'Evenement introuvable')]
    public function show(string $eventId): JsonResponse
    {
        // This endpoint accepts session tokens for public access
        $event = $this->eventService->findById($eventId);
        return $this->json($event);
    }
}

