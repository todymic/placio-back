<?php

namespace App\Controller;

use App\Dto\ChartObjectNode;
use App\Dto\ChartObjectsUpdateRequest;
use App\Dto\ChartRequest;
use App\Service\ChartService;
use OpenApi\Attributes as OA;
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
    #[OA\Tag(name: 'Charts')]
    #[OA\Response(response: 200, description: 'Liste des charts')]
    public function list(): JsonResponse
    {
        $charts = $this->chartService->findAll();
        return $this->json($charts);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'slug'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Main Hall'),
                new OA\Property(property: 'slug', type: 'string', example: 'main-hall'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Chart cree')]
    #[OA\Response(response: 403, description: 'Acces backoffice requis')]
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
    #[OA\Tag(name: 'Charts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Chart')]
    #[OA\Response(response: 404, description: 'Chart introuvable')]
    public function show(string $id): JsonResponse
    {
        $chart = $this->chartService->findById($id);
        return $this->json($chart);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['name', 'slug'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Updated Hall'),
                new OA\Property(property: 'slug', type: 'string', example: 'updated-hall'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Chart modifie')]
    #[OA\Response(response: 404, description: 'Chart introuvable')]
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
    #[OA\Tag(name: 'Charts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['objects'],
            properties: [
                new OA\Property(
                    property: 'objects',
                    type: 'array',
                    items: new OA\Items(type: 'object')
                ),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Objets du chart mis a jour')]
    #[OA\Response(response: 404, description: 'Chart introuvable')]
    public function updateObjects(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $objects = array_map(fn($obj) => ChartObjectNode::fromArray($obj), $data['objects'] ?? []);

        $response = $this->chartService->updateObjects($id, $objects);
        return $this->json($response);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    #[OA\Tag(name: 'Charts')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
    #[OA\Response(response: 200, description: 'Chart supprime')]
    #[OA\Response(response: 404, description: 'Chart introuvable')]
    public function delete(string $id): JsonResponse
    {
        $this->chartService->delete($id);
        return $this->json(['message' => 'Chart deleted']);
    }
}

