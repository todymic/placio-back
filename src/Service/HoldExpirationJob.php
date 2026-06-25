<?php

namespace App\Service;

use App\Entity\SeatStatus;
use App\Repository\EventSeatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Predis\Client;
use Psr\Log\LoggerInterface;

class HoldExpirationJob
{
    private Client $redis;

    public function __construct(
        private EventSeatRepository $eventSeatRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        string $redisUrl = 'tcp://127.0.0.1:6379',
    ) {
        $this->redis = new Client($redisUrl);
    }

    public function __invoke(): void
    {
        $expiredSeats = $this->eventSeatRepository->findByStatusAndHeldUntilBefore(
            SeatStatus::HOLD,
            new \DateTimeImmutable()
        );

        if (empty($expiredSeats)) {
            return;
        }

        // Group by event
        $seatsByEvent = [];
        $redisKeysToDelete = [];

        foreach ($expiredSeats as $seat) {
            $eventId = $seat->getEvent()->getId()->toRfc4122();
            if (!isset($seatsByEvent[$eventId])) {
                $seatsByEvent[$eventId] = [];
            }
            $seatsByEvent[$eventId][] = $seat;

            // Collect Redis keys to delete
            $redisKeysToDelete[] = "hold:{$eventId}:{$seat->getSeatKey()}";
        }

        // Update DB
        foreach ($expiredSeats as $seat) {
            $seat->setStatus(SeatStatus::AVAILABLE);
            $seat->setHoldToken(null);
            $seat->setHeldUntil(null);
            $this->em->persist($seat);
        }
        $this->em->flush();

        // Clean Redis
        if (!empty($redisKeysToDelete)) {
            $this->redis->del(...$redisKeysToDelete);
        }

        $count = count($expiredSeats);
        $this->logger->info("Automatic expiration: {$count} seats released");
    }
}


