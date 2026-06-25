<?php

namespace App\Controller;

use App\Dto\UserResponse;
use App\Service\UserService;
use OpenApi\Attributes as OA;
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
    #[OA\Tag(name: 'Auth')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', example: 'user@example.com'),
                new OA\Property(property: 'password', type: 'string', example: 'ChangeMe123!'),
                new OA\Property(property: 'displayName', type: 'string', example: 'John Doe'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Utilisateur cree')]
    #[OA\Response(response: 400, description: 'Payload invalide ou email deja utilise')]
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
    #[OA\Tag(name: 'Auth')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', example: 'user@example.com'),
                new OA\Property(property: 'password', type: 'string', example: 'ChangeMe123!'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Token JWT genere',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'token', type: 'string'),
        ])
    )]
    #[OA\Response(response: 401, description: 'Identifiants invalides')]
    public function login(Request $request): JsonResponse
    {
        // This endpoint is handled by Lexik JWT Authentication Bundle
        // The bundle automatically generates a JWT token for valid credentials
        return $this->json(['message' => 'Login endpoint']);
    }

    #[Route('/me', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[OA\Tag(name: 'Auth')]
    #[OA\Security(name: 'Bearer')]
    #[OA\Response(response: 200, description: 'Profil courant')]
    #[OA\Response(response: 401, description: 'Non authentifie')]
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

