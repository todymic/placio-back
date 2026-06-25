<?php

namespace App\Controller;

use App\Dto\EventRequest;
use App\Service\EventService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/events')]
class EventController extends AbstractController
{
    public function __construct(
        private EventService $eventService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Events')]
    #[OA\Security(name: 'Bearer')]
    #[OA\Security(name: 'ApiKeyId')]
    #[OA\Security(name: 'ApiKeySecret')]
    #[OA\Response(response: 200, description: 'Liste des evenements')]
    public function list(): JsonResponse
    {
        $events = $this->eventService->findAll();
        return $this->json($events);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Events')]
    #[OA\Security(name: 'Bearer')]
    #[OA\Security(name: 'ApiKeyId')]
    #[OA\Security(name: 'ApiKeySecret')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['title', 'identifier'],
            properties: [
                new OA\Property(property: 'title', type: 'string', example: 'Concert 2026'),
                new OA\Property(property: 'identifier', type: 'string', example: 'concert-2026'),
                new OA\Property(property: 'chartId', type: 'string', format: 'uuid', nullable: true),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Evenement cree')]
    #[OA\Response(response: 403, description: 'Acces backoffice requis')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $eventRequest = new EventRequest(
            $data['title'] ?? '',
            $data['identifier'] ?? '',
            $data['chartId'] ?? null,
        );

        $response = $this->eventService->create($eventRequest);
        return $this->json($response, Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Events')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Security(name: 'Bearer')]
    #[OA\Security(name: 'ApiKeyId')]
    #[OA\Security(name: 'ApiKeySecret')]
    #[OA\Response(response: 200, description: 'Evenement detaille')]
    #[OA\Response(response: 404, description: 'Evenement introuvable')]
    public function show(string $id): JsonResponse
    {
        $event = $this->eventService->findById($id);
        return $this->json($event);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Events')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Security(name: 'Bearer')]
    #[OA\Security(name: 'ApiKeyId')]
    #[OA\Security(name: 'ApiKeySecret')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['title', 'identifier'],
            properties: [
                new OA\Property(property: 'title', type: 'string', example: 'Concert 2026 - Updated'),
                new OA\Property(property: 'identifier', type: 'string', example: 'concert-2026-updated'),
                new OA\Property(property: 'chartId', type: 'string', format: 'uuid', nullable: true),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Evenement modifie')]
    #[OA\Response(response: 404, description: 'Evenement introuvable')]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $eventRequest = new EventRequest(
            $data['title'] ?? '',
            $data['identifier'] ?? '',
            $data['chartId'] ?? null,
        );

        $response = $this->eventService->update($id, $eventRequest);
        return $this->json($response);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Events')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Security(name: 'Bearer')]
    #[OA\Security(name: 'ApiKeyId')]
    #[OA\Security(name: 'ApiKeySecret')]
    #[OA\Response(response: 200, description: 'Evenement supprime')]
    #[OA\Response(response: 404, description: 'Evenement introuvable')]
    public function delete(string $id): JsonResponse
    {
        $this->eventService->delete($id);
        return $this->json(['message' => 'Event deleted']);
    }

    #[Route('/{eventId}/link-chart/{chartId}', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Events')]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Parameter(name: 'chartId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Security(name: 'Bearer')]
    #[OA\Security(name: 'ApiKeyId')]
    #[OA\Security(name: 'ApiKeySecret')]
    #[OA\Response(response: 200, description: 'Chart lie a l evenement')]
    #[OA\Response(response: 404, description: 'Evenement ou chart introuvable')]
    public function linkChart(string $eventId, string $chartId): JsonResponse
    {
        $response = $this->eventService->linkChart($eventId, $chartId);
        return $this->json($response);
    }

    #[Route('/{id}/seats', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Events')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Security(name: 'Bearer')]
    #[OA\Security(name: 'ApiKeyId')]
    #[OA\Security(name: 'ApiKeySecret')]
    #[OA\Response(response: 200, description: 'Liste des statuts de sieges')]
    #[OA\Response(response: 404, description: 'Evenement introuvable')]
    public function getSeats(string $id): JsonResponse
    {
        $event = $this->eventService->findById($id);
        return $this->json(['seats' => $event->seats]);
    }
}

