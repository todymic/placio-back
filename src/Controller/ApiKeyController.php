<?php

namespace App\Controller;

use App\Dto\ApiKeyRequest;
use App\Service\ApiKeyService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/api-keys')]
#[IsGranted('ROLE_BACKOFFICE')]
class ApiKeyController extends AbstractController
{
    public function __construct(
        private ApiKeyService $apiKeyService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Tag(name: 'API Keys')]
    #[OA\Security(name: 'Bearer')]
    #[OA\Security(name: 'ApiKeyId')]
    #[OA\Security(name: 'ApiKeySecret')]
    #[OA\Response(response: 200, description: 'Liste des API keys')]
    #[OA\Response(response: 401, description: 'Non authentifie')]
    #[OA\Response(response: 403, description: 'Acces backoffice requis')]
    public function list(): JsonResponse
    {
        $keys = $this->apiKeyService->findAll();
        return $this->json($keys);
    }

    #[Route('', methods: ['POST'])]
    #[OA\Tag(name: 'API Keys')]
    #[OA\Security(name: 'Bearer')]
    #[OA\Security(name: 'ApiKeyId')]
    #[OA\Security(name: 'ApiKeySecret')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'scope'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'BO key demo'),
                new OA\Property(property: 'scope', type: 'string', enum: ['backoffice', 'public'], example: 'backoffice'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'API key creee (secret retourne une seule fois)')]
    #[OA\Response(response: 400, description: 'Payload invalide')]
    #[OA\Response(response: 401, description: 'Non authentifie')]
    #[OA\Response(response: 403, description: 'Acces backoffice requis')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $apiKeyRequest = new ApiKeyRequest(
            $data['name'] ?? '',
            $data['scope'] ?? 'public',
        );

        $response = $this->apiKeyService->create($apiKeyRequest);
        return $this->json($response, Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[OA\Tag(name: 'API Keys')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Security(name: 'Bearer')]
    #[OA\Security(name: 'ApiKeyId')]
    #[OA\Security(name: 'ApiKeySecret')]
    #[OA\Response(response: 200, description: 'API key desactivee')]
    #[OA\Response(response: 401, description: 'Non authentifie')]
    #[OA\Response(response: 403, description: 'Acces backoffice requis')]
    #[OA\Response(response: 404, description: 'API key introuvable')]
    public function delete(string $id): JsonResponse
    {
        $this->apiKeyService->deactivate($id);
        return $this->json(['message' => 'API Key deactivated']);
    }
}

