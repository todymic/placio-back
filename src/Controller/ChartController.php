<?php

namespace App\Controller;

use App\Dto\ChartObjectNode;
use App\Dto\ChartObjectsUpdateRequest;
use App\Dto\ChartRequest;
use App\Service\ChartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/charts')]
class ChartController extends AbstractController
{
    public function __construct(
        private ChartService $chartService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function list(): JsonResponse
    {
        $charts = $this->chartService->findAll();
        return $this->json($charts);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $chartRequest = new ChartRequest(
            $data['name'] ?? '',
            $data['slug'] ?? '',
        );

        $response = $this->chartService->create($chartRequest);
        return $this->json($response, Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function show(string $id): JsonResponse
    {
        $chart = $this->chartService->findById($id);
        return $this->json($chart);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $chartRequest = new ChartRequest(
            $data['name'] ?? '',
            $data['slug'] ?? '',
        );

        $response = $this->chartService->update($id, $chartRequest);
        return $this->json($response);
    }

    #[Route('/{id}/objects', methods: ['PUT'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function updateObjects(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $objects = array_map(fn($obj) => ChartObjectNode::fromArray($obj), $data['objects'] ?? []);

        $response = $this->chartService->updateObjects($id, $objects);
        return $this->json($response);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function delete(string $id): JsonResponse
    {
        $this->chartService->delete($id);
        return $this->json(['message' => 'Chart deleted']);
    }
}

