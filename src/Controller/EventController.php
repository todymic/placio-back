<?php

namespace App\Controller;

use App\Dto\EventRequest;
use App\Service\EventService;
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
    public function list(): JsonResponse
    {
        $events = $this->eventService->findAll();
        return $this->json($events);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
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
    public function show(string $id): JsonResponse
    {
        $event = $this->eventService->findById($id);
        return $this->json($event);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_BACKOFFICE')]
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
    public function delete(string $id): JsonResponse
    {
        $this->eventService->delete($id);
        return $this->json(['message' => 'Event deleted']);
    }

    #[Route('/{eventId}/link-chart/{chartId}', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function linkChart(string $eventId, string $chartId): JsonResponse
    {
        $response = $this->eventService->linkChart($eventId, $chartId);
        return $this->json($response);
    }

    #[Route('/{id}/seats', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function getSeats(string $id): JsonResponse
    {
        $event = $this->eventService->findById($id);
        return $this->json(['seats' => $event->seats]);
    }
}

