<?php

namespace App\Controller;

use App\Dto\SessionTokenRequest;
use App\Security\ApiKeyUser;
use App\Service\SessionTokenService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/public/sessions')]
class PublicSessionController extends AbstractController
{
    public function __construct(
        private SessionTokenService $sessionTokenService,
    ) {
    }

    /**
     * Crée un session token pour le widget.
     * Appelé côté serveur du site externe avec la clé secrète (sk_pub_xxx).
     * Le token retourné est passé au navigateur pour initialiser le widget.
     */
    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_PUBLIC')]
    #[OA\Tag(name: 'Widget')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['eventId'],
            properties: [
                new OA\Property(property: 'eventId', type: 'string', format: 'uuid'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Session token créé')]
    #[OA\Response(response: 403, description: 'Clé publique requise (scope PUBLIC)')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        /** @var ApiKeyUser $apiKeyUser */
        $apiKeyUser = $this->getUser();

        $response = $this->sessionTokenService->create(
            new SessionTokenRequest($data['eventId'] ?? ''),
            $apiKeyUser->getUserIdentifier(),
        );

        return $this->json($response, Response::HTTP_CREATED);
    }

    /**
     * Renouvelle un session token expiré.
     * Le widget appelle cet endpoint (via le site externe) quand il reçoit un 401.
     * Le holdToken est préservé pour ne pas perdre les sièges en attente.
     */
    #[Route('/refresh', methods: ['POST'])]
    #[IsGranted('ROLE_PUBLIC')]
    #[OA\Tag(name: 'Widget')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['sessionToken'],
            properties: [
                new OA\Property(property: 'sessionToken', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Nouveau session token')]
    public function refresh(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $response = $this->sessionTokenService->refresh($data['sessionToken'] ?? '');

        return $this->json($response);
    }
}
