<?php

namespace App\Service;

use App\Dto\CategoryRequest;
use App\Dto\CategoryResponse;
use App\Entity\Category;
use App\Exception\DuplicateKeyException;
use App\Exception\ResourceNotFoundException;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

class CategoryService
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private EntityManagerInterface $em,
    ) {
    }

    public function create(CategoryRequest $request): CategoryResponse
    {
        $existing = $this->categoryRepository->findByKey($request->key);
        if ($existing) {
            throw new DuplicateKeyException("Category with key '{$request->key}' already exists");
        }

        $category = new Category();
        $category->setName($request->name);
        $category->setKey($request->key);
        $category->setColor($request->color);

        $this->em->persist($category);
        $this->em->flush();

        return $this->toResponse($category);
    }

    public function findAll(): array
    {
        $categories = $this->categoryRepository->findAll();
        return array_map(fn(Category $cat) => $this->toResponse($cat), $categories);
    }

    public function findById(string $id): CategoryResponse
    {
        $category = $this->categoryRepository->find($id);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }
        return $this->toResponse($category);
    }

    public function update(string $id, CategoryRequest $request): CategoryResponse
    {
        $category = $this->categoryRepository->find($id);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        if ($request->key !== $category->getKey()) {
            $existing = $this->categoryRepository->findByKey($request->key);
            if ($existing) {
                throw new DuplicateKeyException("Category with key '{$request->key}' already exists");
            }
        }

        $category->setName($request->name);
        $category->setKey($request->key);
        $category->setColor($request->color);

        $this->em->persist($category);
        $this->em->flush();

        return $this->toResponse($category);
    }

    public function delete(string $id): void
    {
        $category = $this->categoryRepository->find($id);
        if (!$category) {
            throw new ResourceNotFoundException('Category not found');
        }

        $this->em->remove($category);
        $this->em->flush();
    }

    private function toResponse(Category $category): CategoryResponse
    {
        return new CategoryResponse(
            $category->getId(),
            $category->getName(),
            $category->getKey(),
            $category->getColor(),
        );
    }
}

