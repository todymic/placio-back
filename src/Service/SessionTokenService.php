<?php

namespace App\Service;

use App\Dto\SessionTokenRequest;
use App\Dto\SessionTokenResponse;
use App\Exception\ResourceNotFoundException;
use App\Repository\EventRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Symfony\Component\Uid\Uuid;

class SessionTokenService
{
    private const TTL = 3600; // 1 heure

    public function __construct(
        private JWTEncoderInterface $jwtEncoder,
        private EventRepository $eventRepository,
    ) {
    }

    public function create(SessionTokenRequest $request, string $publicKeyId): SessionTokenResponse
    {
        $event = $this->eventRepository->find($request->eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        $holdToken = Uuid::v4()->toRfc4122();

        $token = $this->jwtEncoder->encode([
            'sub'        => 'widget',
            'pub_key_id' => $publicKeyId,
            'event_id'   => $request->eventId,
            'hold_token' => $holdToken,
            'exp'        => time() + self::TTL,
        ]);

        return new SessionTokenResponse(
            sessionToken: $token,
            holdToken: $holdToken,
            expiresIn: self::TTL,
            eventId: $request->eventId,
        );
    }

    /**
     * Décode et valide un session token widget.
     * Retourne le payload JWT ou lève une exception si invalide/expiré.
     *
     * @return array{sub: string, pub_key_id: string, event_id: string, hold_token: string, exp: int}
     * @throws JWTDecodeFailureException
     */
    public function decode(string $token): array
    {
        $payload = $this->jwtEncoder->decode($token);

        if (($payload['sub'] ?? '') !== 'widget') {
            throw new JWTDecodeFailureException(
                JWTDecodeFailureException::INVALID_TOKEN,
                'Not a widget token'
            );
        }

        return $payload;
    }

    /**
     * Renouvelle un token expiré ou proche de l'expiration.
     * Préserve le même holdToken pour ne pas casser les holds en cours.
     */
    public function refresh(string $expiredToken): SessionTokenResponse
    {
        // decode() lève une exception si le token est invalide (signature wronge, etc.)
        // Pour les tokens expirés on doit désactiver la vérification d'expiration.
        // Lexik ne propose pas cette option — on décode manuellement le payload.
        $parts = explode('.', $expiredToken);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Malformed token');
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        if (($payload['sub'] ?? '') !== 'widget') {
            throw new \InvalidArgumentException('Not a widget token');
        }

        $newToken = $this->jwtEncoder->encode([
            'sub'        => 'widget',
            'pub_key_id' => $payload['pub_key_id'],
            'event_id'   => $payload['event_id'],
            'hold_token' => $payload['hold_token'],
            'exp'        => time() + self::TTL,
        ]);

        return new SessionTokenResponse(
            sessionToken: $newToken,
            holdToken: $payload['hold_token'],
            expiresIn: self::TTL,
            eventId: $payload['event_id'],
        );
    }
}
