<?php

namespace App\Controller;

use App\Dto\CategoryRequest;
use App\Service\CategoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/categories')]
class CategoryController extends AbstractController
{
    public function __construct(
        private CategoryService $categoryService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function list(): JsonResponse
    {
        $categories = $this->categoryService->findAll();
        return $this->json($categories);
    }

    #[Route('', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $categoryRequest = new CategoryRequest(
            $data['name'] ?? '',
            $data['key'] ?? '',
            $data['color'] ?? '',
        );

        $response = $this->categoryService->create($categoryRequest);
        return $this->json($response, Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function show(string $id): JsonResponse
    {
        $category = $this->categoryService->findById($id);
        return $this->json($category);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $categoryRequest = new CategoryRequest(
            $data['name'] ?? '',
            $data['key'] ?? '',
            $data['color'] ?? '',
        );

        $response = $this->categoryService->update($id, $categoryRequest);
        return $this->json($response);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function delete(string $id): JsonResponse
    {
        $this->categoryService->delete($id);
        return $this->json(['message' => 'Category deleted']);
    }
}

