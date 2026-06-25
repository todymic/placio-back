<?php

namespace App\Controller;

use App\Dto\BookRequest;
use App\Dto\ChangeStatusRequest;
use App\Dto\HoldRequest;
use App\Dto\ReleaseRequest;
use App\Entity\SeatStatus;
use App\Service\BookingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/events/{eventId}')]
class BookingController extends AbstractController
{
    public function __construct(
        private BookingService $bookingService,
    ) {
    }

    #[Route('/hold', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function hold(string $eventId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        try {
            $response = $this->bookingService->holdSeats(
                Uuid::fromString($eventId),
                $data['seatKeys'] ?? [],
                $data['holdToken'] ?? '',
            );
            return $this->json($response);
        } catch (\Exception $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                $e->getCode() ?: Response::HTTP_BAD_REQUEST
            );
        }
    }

    #[Route('/book', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function book(string $eventId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        try {
            $response = $this->bookingService->bookSeats(
                Uuid::fromString($eventId),
                $data['seatKeys'] ?? [],
                $data['holdToken'] ?? '',
            );
            return $this->json($response);
        } catch (\Exception $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                $e->getCode() ?: Response::HTTP_BAD_REQUEST
            );
        }
    }

    #[Route('/release', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function release(string $eventId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        try {
            $this->bookingService->releaseSeats(
                Uuid::fromString($eventId),
                $data['seatKeys'] ?? [],
                $data['holdToken'] ?? '',
            );
            return $this->json(['message' => 'Seats released']);
        } catch (\Exception $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                $e->getCode() ?: Response::HTTP_BAD_REQUEST
            );
        }
    }

    #[Route('/change-status', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function changeStatus(string $eventId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        try {
            $status = SeatStatus::from($data['status'] ?? 'available');
            $this->bookingService->changeStatus(
                Uuid::fromString($eventId),
                $data['seatKeys'] ?? [],
                $status,
            );
            return $this->json(['message' => 'Seat status changed']);
        } catch (\Exception $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                $e->getCode() ?: Response::HTTP_BAD_REQUEST
            );
        }
    }
}

