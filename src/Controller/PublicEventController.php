<?php

namespace App\Controller;

use App\Dto\SessionTokenRequest;
use App\Service\EventService;
use App\Service\SessionTokenService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/public')]
class PublicEventController extends AbstractController
{
    public function __construct(
        private EventService $eventService,
        private SessionTokenService $sessionTokenService,
    ) {
    }

    /**
     * Génère un session token pour la page de réservation publique standalone.
     * Aucune authentification requise — l'événement doit juste exister.
     * Le token est court (1h) et lié à cet eventId uniquement.
     */
    #[Route('/events/{eventId}/session', methods: ['GET'])]
    #[OA\Tag(name: 'Public')]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 201, description: 'Session token créé')]
    #[OA\Response(response: 404, description: 'Événement introuvable')]
    public function createSession(string $eventId): JsonResponse
    {
        $response = $this->sessionTokenService->create(
            new SessionTokenRequest($eventId),
            'public',
        );

        return $this->json($response, Response::HTTP_CREATED);
    }
}
