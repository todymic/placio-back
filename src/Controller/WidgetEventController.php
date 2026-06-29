<?php

namespace App\Controller;

use App\Service\EventService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/widget/events/{eventId}')]
#[IsGranted('ROLE_WIDGET')]
#[OA\Tag(name: 'Widget')]
class WidgetEventController extends AbstractController
{
    public function __construct(
        private EventService $eventService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Parameter(name: 'eventId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Détail événement avec plan et statuts des sièges')]
    public function show(string $eventId): JsonResponse
    {
        $event = $this->eventService->findById($eventId);
        return $this->json($event);
    }
}
