<?php

namespace App\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class JwtAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): ?bool
    {
        $header = $request->headers->get('Authorization');
        if (!$header) {
            return false;
        }

        return str_starts_with($header, 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization');
        $jwt = substr($header, 7);

        $key = getenv('APP_SECRET') ?: $_ENV['APP_SECRET'] ?? null;
        if (!$key) {
            throw new CustomUserMessageAuthenticationException('Server misconfigured: missing APP_SECRET');
        }

        try {
            $payload = JWT::decode($jwt, new Key($key, 'HS256'));
        } catch (\Throwable $e) {
            throw new CustomUserMessageAuthenticationException('Invalid token');
        }

        $username = $payload->username ?? null;
        if (!$username) {
            throw new CustomUserMessageAuthenticationException('Invalid token payload');
        }

        return new SelfValidatingPassport(new UserBadge($username));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Authentication Failed', 'message' => $exception->getMessage()], Response::HTTP_UNAUTHORIZED);
    }
}
