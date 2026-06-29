<?php

namespace App\Service;

use App\Dto\ChartObjectNode;
use App\Dto\EventDetailResponse;
use App\Dto\EventRequest;
use App\Dto\EventResponse;
use App\Dto\EventSeatStatusDto;
use App\Entity\Chart;
use App\Entity\Event;
use App\Entity\EventSeat;
use App\Entity\SeatStatus;
use App\Exception\DuplicateKeyException;
use App\Exception\ResourceNotFoundException;
use App\Repository\ChartRepository;
use App\Repository\EventRepository;
use App\Repository\EventSeatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class EventService
{
    public function __construct(
        private EventRepository $eventRepository,
        private ChartRepository $chartRepository,
        private EventSeatRepository $eventSeatRepository,
        private EntityManagerInterface $em,
    ) {
    }

    public function create(EventRequest $request): EventResponse
    {
        $existing = $this->eventRepository->findByIdentifier($request->identifier);
        if ($existing) {
            throw new DuplicateKeyException("Event with identifier '{$request->identifier}' already exists");
        }

        $event = new Event();
        $event->setTitle($request->title);
        $event->setIdentifier($request->identifier);

        if ($request->chartId) {
            $chart = $this->chartRepository->find($request->chartId);
            if (!$chart) {
                throw new ResourceNotFoundException('Chart not found');
            }
            $event->setChart($chart);
            $this->initializeSeats($event, $chart);
        }

        $this->em->persist($event);
        $this->em->flush();

        return $this->toResponse($event);
    }

    public function findAll(): array
    {
        $events = $this->eventRepository->findAll();
        return array_map(fn(Event $event) => $this->toResponse($event), $events);
    }

    public function findById(string $id): EventDetailResponse
    {
        $event = $this->eventRepository->find($id);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }
        return $this->toDetailResponse($event);
    }

    public function update(string $id, EventRequest $request): EventResponse
    {
        $event = $this->eventRepository->find($id);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        if ($request->identifier !== $event->getIdentifier()) {
            $existing = $this->eventRepository->findByIdentifier($request->identifier);
            if ($existing) {
                throw new DuplicateKeyException("Event with identifier '{$request->identifier}' already exists");
            }
        }

        $event->setTitle($request->title);
        $event->setIdentifier($request->identifier);

        $this->em->persist($event);
        $this->em->flush();

        return $this->toResponse($event);
    }

    public function delete(string $id): void
    {
        $event = $this->eventRepository->find($id);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        $this->em->remove($event);
        $this->em->flush();
    }

    public function linkChart(string $eventId, string $chartId): EventResponse
    {
        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        $chart = $this->chartRepository->find($chartId);
        if (!$chart) {
            throw new ResourceNotFoundException('Chart not found');
        }

        // Remove existing seats
        foreach ($event->getSeats() as $seat) {
            $this->em->remove($seat);
        }

        $event->setChart($chart);
        $this->initializeSeats($event, $chart);

        $this->em->persist($event);
        $this->em->flush();

        return $this->toResponse($event);
    }

    private function initializeSeats(Event $event, Chart $chart): void
    {
        $objects = $chart->getObjects();
        $this->createSeatsFromObjects($event, $objects);
    }

    private function createSeatsFromObjects(Event $event, array $objects): void
    {
        foreach ($objects as $object) {
            if ($object->type === 'seat') {
                $seat = new EventSeat();
                $seat->setEvent($event);
                $seat->setSeatKey($object->key);
                $seat->setStatus(SeatStatus::AVAILABLE);
                $this->em->persist($seat);
            } elseif ($object->type === 'section' || $object->type === 'row') {
                if (!empty($object->children)) {
                    $this->createSeatsFromObjects($event, $object->children);
                }
            } elseif ($object->type === 'table' && $object->seatCount) {
                for ($i = 1; $i <= $object->seatCount; $i++) {
                    $seat = new EventSeat();
                    $seat->setEvent($event);
                    $seat->setSeatKey("{$object->key}-{$i}");
                    $seat->setStatus(SeatStatus::AVAILABLE);
                    $this->em->persist($seat);
                }
            }
        }
    }

    private function toResponse(Event $event): EventResponse
    {
        return new EventResponse(
            $event->getId(),
            $event->getTitle(),
            $event->getIdentifier(),
            $event->getChart()?->getId(),
            $event->getChart()?->getName(),
            $event->getCreatedAt(),
        );
    }

    private function toDetailResponse(Event $event): EventDetailResponse
    {
        $seats = $this->eventSeatRepository->findByEventId($event->getId());
        $seatDtos = array_map(fn(EventSeat $seat) =>
            new EventSeatStatusDto($seat->getSeatKey(), $seat->getStatus()->value),
            $seats
        );

        $chartObjects = $event->getChart()?->getObjectsJson() ?? [];

        return new EventDetailResponse(
            $event->getId(),
            $event->getTitle(),
            $event->getIdentifier(),
            $event->getChart()?->getId(),
            $event->getChart()?->getName(),
            $event->getCreatedAt(),
            $seatDtos,
            $chartObjects,
        );
    }
}

