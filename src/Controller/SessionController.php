<?php

namespace App\Controller;

use App\Dto\SessionRequest;
use App\Service\SessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/sessions')]
class SessionController extends AbstractController
{
    public function __construct(
        private SessionService $sessionService,
    ) {
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Get keyId from API Key auth (if available)
        $keyId = $request->headers->get('X-Api-Key-Id') ?? 'public';

        try {
            $response = $this->sessionService->createSession(
                Uuid::fromString($data['eventId'] ?? ''),
                $keyId,
            );
            return $this->json($response, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                Response::HTTP_BAD_REQUEST
            );
        }
    }
}

