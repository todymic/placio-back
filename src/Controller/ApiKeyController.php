<?php

namespace App\Controller;

use App\Dto\ApiKeyRequest;
use App\Service\ApiKeyService;
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
    public function list(): JsonResponse
    {
        $keys = $this->apiKeyService->findAll();
        return $this->json($keys);
    }

    #[Route('', methods: ['POST'])]
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
    public function delete(string $id): JsonResponse
    {
        $this->apiKeyService->deactivate($id);
        return $this->json(['message' => 'API Key deactivated']);
    }
}

