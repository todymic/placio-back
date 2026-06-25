<?php

namespace App\Service;

use App\Dto\ChartObjectNode;
use App\Dto\ChartRequest;
use App\Dto\ChartResponse;
use App\Entity\Chart;
use App\Exception\DuplicateKeyException;
use App\Exception\ResourceNotFoundException;
use App\Repository\ChartRepository;
use Doctrine\ORM\EntityManagerInterface;

class ChartService
{
    public function __construct(
        private ChartRepository $chartRepository,
        private EntityManagerInterface $em,
    ) {
    }

    public function create(ChartRequest $request): ChartResponse
    {
        $existing = $this->chartRepository->findBySlug($request->slug);
        if ($existing) {
            throw new DuplicateKeyException("Chart with slug '{$request->slug}' already exists");
        }

        $chart = new Chart();
        $chart->setName($request->name);
        $chart->setSlug($request->slug);
        $chart->setObjectsJson([]);

        $this->em->persist($chart);
        $this->em->flush();

        return $this->toResponse($chart);
    }

    public function findAll(): array
    {
        $charts = $this->chartRepository->findAll();
        return array_map(fn(Chart $chart) => $this->toResponse($chart), $charts);
    }

    public function findById(string $id): ChartResponse
    {
        $chart = $this->chartRepository->find($id);
        if (!$chart) {
            throw new ResourceNotFoundException('Chart not found');
        }
        return $this->toResponse($chart);
    }

    public function findBySlug(string $slug): ChartResponse
    {
        $chart = $this->chartRepository->findBySlug($slug);
        if (!$chart) {
            throw new ResourceNotFoundException('Chart not found');
        }
        return $this->toResponse($chart);
    }

    public function update(string $id, ChartRequest $request): ChartResponse
    {
        $chart = $this->chartRepository->find($id);
        if (!$chart) {
            throw new ResourceNotFoundException('Chart not found');
        }

        if ($request->slug !== $chart->getSlug()) {
            $existing = $this->chartRepository->findBySlug($request->slug);
            if ($existing) {
                throw new DuplicateKeyException("Chart with slug '{$request->slug}' already exists");
            }
        }

        $chart->setName($request->name);
        $chart->setSlug($request->slug);

        $this->em->persist($chart);
        $this->em->flush();

        return $this->toResponse($chart);
    }

    public function updateObjects(string $id, array $objects): ChartResponse
    {
        $chart = $this->chartRepository->find($id);
        if (!$chart) {
            throw new ResourceNotFoundException('Chart not found');
        }

        $chartObjects = array_map(fn($obj) =>
            $obj instanceof ChartObjectNode ? $obj->toArray() : $obj,
            $objects
        );

        $chart->setObjectsJson($chartObjects);

        $this->em->persist($chart);
        $this->em->flush();

        return $this->toResponse($chart);
    }

    public function delete(string $id): void
    {
        $chart = $this->chartRepository->find($id);
        if (!$chart) {
            throw new ResourceNotFoundException('Chart not found');
        }

        $this->em->remove($chart);
        $this->em->flush();
    }

    private function toResponse(Chart $chart): ChartResponse
    {
        $objects = array_map(
            fn($obj) => is_array($obj) ? ChartObjectNode::fromArray($obj)->toArray() : $obj->toArray(),
            $chart->getObjects()
        );

        return new ChartResponse(
            $chart->getId(),
            $chart->getName(),
            $chart->getSlug(),
            $objects,
            $chart->getUpdatedAt(),
        );
    }
}

