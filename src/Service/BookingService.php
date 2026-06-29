<?php

namespace App\Service;

use App\Dto\BookResponse;
use App\Dto\HoldResponse;
use App\Dto\SeatConflictDetail;
use App\Entity\EventSeat;
use App\Entity\SeatStatus;
use App\Exception\ResourceNotFoundException;
use App\Exception\SeatNotAvailableException;
use App\Repository\EventRepository;
use App\Repository\EventSeatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Predis\Client;
use Symfony\Component\Uid\Uuid;

class BookingService
{
    private Client $redis;
    private int $holdDurationMinutes;

    public function __construct(
        private EventSeatRepository $eventSeatRepository,
        private EventRepository $eventRepository,
        private EntityManagerInterface $em,
        string $redisUrl = 'tcp://127.0.0.1:6379',
        int $holdDurationMinutes = 10,
    ) {
        $this->redis = new Client($redisUrl);
        $this->holdDurationMinutes = $holdDurationMinutes;
    }

    /** Publie les changements de statut sur le canal Redis pour les clients SSE. */
    private function publishSeatChanges(Uuid $eventId, array $seats): void
    {
        $changes = array_map(fn(EventSeat $s) => [
            'seatKey' => $s->getSeatKey(),
            'status'  => $s->getStatus()->value,
        ], $seats);

        $this->redis->publish("seats:{$eventId}", json_encode($changes));
    }

    public function holdSeats(Uuid $eventId, array $seatKeys, string $holdToken): HoldResponse
    {
        // Deduplicate
        $seatKeys = array_unique($seatKeys);
        if (empty($seatKeys)) {
            throw new \InvalidArgumentException('Seat keys list is empty');
        }

        // Verify event exists
        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        // Verify seats exist
        $seats = $this->eventSeatRepository->findByEventIdAndSeatKeyIn($eventId, $seatKeys);
        if (count($seats) !== count($seatKeys)) {
            throw new ResourceNotFoundException('One or more seats not found');
        }

        // Check seat availability
        $conflicts = [];
        foreach ($seats as $seat) {
            if ($seat->getStatus() === SeatStatus::HOLD && $seat->getHoldToken() === $holdToken) {
                // Same token - will renew
                continue;
            }
            if ($seat->getStatus() !== SeatStatus::AVAILABLE) {
                $conflicts[] = new SeatConflictDetail($seat->getSeatKey(), $seat->getStatus()->value);
            }
        }

        if (!empty($conflicts)) {
            throw new SeatNotAvailableException($conflicts, 'One or more seats are not available');
        }

        // Hold seats
        $holdDurationSeconds = $this->holdDurationMinutes * 60;
        $expiresAt = new \DateTimeImmutable("+{$this->holdDurationMinutes} minutes");

        // Redis pipeline
        $pipe = $this->redis->pipeline();
        foreach ($seatKeys as $seatKey) {
            $redisKey = "hold:{$eventId}:{$seatKey}";
            $pipe->setex($redisKey, $holdDurationSeconds, $holdToken);
        }
        $sessionKey = "session_seats:{$holdToken}";
        $pipe->setex($sessionKey, $holdDurationSeconds, json_encode($seatKeys));
        $pipe->execute();

        // Update DB
        foreach ($seats as $seat) {
            $seat->setStatus(SeatStatus::HOLD);
            $seat->setHoldToken($holdToken);
            $seat->setHeldUntil($expiresAt);
            $this->em->persist($seat);
        }
        $this->em->flush();
        $this->publishSeatChanges($eventId, $seats);

        return new HoldResponse(
            $holdToken,
            $seatKeys,
            $expiresAt,
            $holdDurationSeconds,
        );
    }

    public function bookSeats(Uuid $eventId, array $seatKeys, string $holdToken): BookResponse
    {
        // Deduplicate
        $seatKeys = array_unique($seatKeys);
        if (empty($seatKeys)) {
            throw new \InvalidArgumentException('Seat keys list is empty');
        }

        // Verify event exists
        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        // Verify seats are on hold with correct token
        $seats = $this->eventSeatRepository->findByEventIdAndSeatKeyIn($eventId, $seatKeys);
        $conflicts = [];

        foreach ($seats as $seat) {
            if ($seat->getStatus() !== SeatStatus::HOLD || $seat->getHoldToken() !== $holdToken) {
                $conflicts[] = new SeatConflictDetail($seat->getSeatKey(), $seat->getStatus()->value);
            }
        }

        if (!empty($conflicts)) {
            throw new SeatNotAvailableException($conflicts, 'One or more seats cannot be booked');
        }

        // Book seats
        foreach ($seats as $seat) {
            $seat->setStatus(SeatStatus::BOOKED);
            $seat->setHoldToken(null);
            $seat->setHeldUntil(null);
            $this->em->persist($seat);
        }
        $this->em->flush();
        $this->publishSeatChanges($eventId, $seats);

        // Clean Redis
        $pipe = $this->redis->pipeline();
        foreach ($seatKeys as $seatKey) {
            $redisKey = "hold:{$eventId}:{$seatKey}";
            $pipe->del($redisKey);
        }
        $sessionKey = "session_seats:{$holdToken}";
        $pipe->del($sessionKey);
        $pipe->execute();

        return new BookResponse(
            $seatKeys,
            $eventId->toRfc4122(),
            new \DateTimeImmutable(),
        );
    }

    public function releaseSeats(Uuid $eventId, array $seatKeys, string $holdToken): void
    {
        // Deduplicate
        $seatKeys = array_unique($seatKeys);
        if (empty($seatKeys)) {
            throw new \InvalidArgumentException('Seat keys list is empty');
        }

        // Verify seats are on hold with correct token
        $seats = $this->eventSeatRepository->findByEventIdAndSeatKeyIn($eventId, $seatKeys);

        foreach ($seats as $seat) {
            if ($seat->getStatus() !== SeatStatus::HOLD || $seat->getHoldToken() !== $holdToken) {
                throw new SeatNotAvailableException([], 'Seat not held by this token');
            }
        }

        // Release seats
        foreach ($seats as $seat) {
            $seat->setStatus(SeatStatus::AVAILABLE);
            $seat->setHoldToken(null);
            $seat->setHeldUntil(null);
            $this->em->persist($seat);
        }
        $this->em->flush();
        $this->publishSeatChanges($eventId, $seats);

        // Clean Redis
        $pipe = $this->redis->pipeline();
        foreach ($seatKeys as $seatKey) {
            $redisKey = "hold:{$eventId}:{$seatKey}";
            $pipe->del($redisKey);
        }
        $sessionKey = "session_seats:{$holdToken}";
        $pipe->del($sessionKey);
        $pipe->execute();
    }

    public function changeStatus(Uuid $eventId, array $seatKeys, SeatStatus $newStatus): void
    {
        // Deduplicate
        $seatKeys = array_unique($seatKeys);
        if (empty($seatKeys)) {
            throw new \InvalidArgumentException('Seat keys list is empty');
        }

        // Verify event exists
        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        // Change status
        $seats = $this->eventSeatRepository->findByEventIdAndSeatKeyIn($eventId, $seatKeys);

        foreach ($seats as $seat) {
            $seat->setStatus($newStatus);
            if ($newStatus !== SeatStatus::HOLD) {
                $seat->setHoldToken(null);
                $seat->setHeldUntil(null);
            }
            $this->em->persist($seat);
        }
        $this->em->flush();
        $this->publishSeatChanges($eventId, $seats);

        // Clean Redis if releasing
        if ($newStatus !== SeatStatus::HOLD) {
            $pipe = $this->redis->pipeline();
            foreach ($seatKeys as $seatKey) {
                $redisKey = "hold:{$eventId}:{$seatKey}";
                $pipe->del($redisKey);
            }
            $pipe->execute();
        }
    }
}

