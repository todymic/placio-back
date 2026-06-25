<?php

namespace App\Service;

use App\Dto\SessionResponse;
use App\Exception\ResourceNotFoundException;
use App\Exception\UnauthorizedException;
use App\Repository\EventRepository;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\Uid\Uuid;

class SessionService
{
    private Configuration $jwtConfig;
    private int $sessionDurationMinutes;

    public function __construct(
        private EventRepository $eventRepository,
        string $jwtSecret,
        int $sessionDurationMinutes = 60,
    ) {
        $this->sessionDurationMinutes = $sessionDurationMinutes;

        $this->jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($jwtSecret)
        );
    }

    public function createSession(Uuid $eventId, string $keyId): SessionResponse
    {
        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        $now = new \DateTimeImmutable();
        $expiresAt = $now->add(new \DateInterval("PT{$this->sessionDurationMinutes}M"));

        $token = $this->jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->withClaim('eventId', $eventId->toRfc4122())
            ->withClaim('keyId', $keyId)
            ->withClaim('scope', 'PUBLIC')
            ->getToken($this->jwtConfig->signer());

        return new SessionResponse(
            $token->toString(),
            $eventId,
            $expiresAt,
        );
    }

    public function validateSession(string $token): array
    {
        try {
            $parsedToken = $this->jwtConfig->parser()->parse($token);

            if (!$this->jwtConfig->validator()->validate($parsedToken, ...$this->jwtConfig->validationConstraints())) {
                throw new UnauthorizedException('Invalid session token');
            }

            return [
                'eventId' => Uuid::fromString($parsedToken->claims()->get('eventId')),
                'keyId' => $parsedToken->claims()->get('keyId'),
            ];
        } catch (\Exception $e) {
            throw new UnauthorizedException('Invalid session token');
        }
    }
}

