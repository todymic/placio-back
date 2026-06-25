<?php

namespace App\Controller;

use App\Dto\UserResponse;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private UserService $userService,
    ) {
    }

    #[Route('/register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        try {
            $user = $this->userService->register(
                $data['email'] ?? '',
                $data['password'] ?? '',
                $data['displayName'] ?? null,
            );

            return $this->json(
                $this->toResponse($user),
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                Response::HTTP_BAD_REQUEST
            );
        }
    }

    #[Route('/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        // This endpoint is handled by Lexik JWT Authentication Bundle
        // The bundle automatically generates a JWT token for valid credentials
        return $this->json(['message' => 'Login endpoint']);
    }

    #[Route('/me', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        return $this->json($this->toResponse($user));
    }

    private function toResponse($user): UserResponse
    {
        return new UserResponse(
            $user->getId(),
            $user->getEmail(),
            $user->getDisplayName(),
            $user->getRoles(),
            $user->getCreatedAt(),
            $user->getLastLoginAt(),
        );
    }
}

