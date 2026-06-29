<?php

namespace App\Security;

use App\Service\SessionTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authentifie les requêtes widget via "Authorization: Widget <sessionToken>".
 * Séparé de Lexik JWT (Bearer) pour éviter tout conflit.
 */
class WidgetSessionAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private SessionTokenService $sessionTokenService,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->headers->get('Authorization', ''), 'Widget ');
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization', '');
        $token  = substr($header, strlen('Widget '));

        if (!$token) {
            throw new AuthenticationException('Missing widget session token');
        }

        try {
            $claims = $this->sessionTokenService->decode($token);
        } catch (\Exception $e) {
            throw new AuthenticationException('Invalid or expired widget session token: ' . $e->getMessage());
        }

        $user = new WidgetSessionUser(
            publicKeyId: $claims['pub_key_id'],
            eventId:     $claims['event_id'],
            holdToken:   $claims['hold_token'],
        );

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), fn() => $user)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNAUTHORIZED);
    }
}
