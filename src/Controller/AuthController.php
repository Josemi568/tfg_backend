<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request, UserRepository $userRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;

        if (!$username || !$password) {
            return new JsonResponse(['error' => 'username and password required'], Response::HTTP_BAD_REQUEST);
        }

        $user = $userRepository->findOneBy(['username' => $username]);

        if (!$user) {
            return new JsonResponse(['error' => 'invalid credentials'], Response::HTTP_UNAUTHORIZED);
        }

        $hashed = $user->getPassword();
        if (!password_verify($password, $hashed)) {
            return new JsonResponse(['error' => 'invalid credentials'], Response::HTTP_UNAUTHORIZED);
        }

        $now = time();
        $exp = $now + 3600; // 1 hour
        $payload = [
            'sub' => $user->getId(),
            'username' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            'iat' => $now,
            'exp' => $exp,
        ];

        $key = getenv('APP_SECRET') ?: $_ENV['APP_SECRET'] ?? null;
        if (!$key) {
            return new JsonResponse(['error' => 'server misconfigured: missing APP_SECRET'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $jwt = JWT::encode($payload, $key, 'HS256');

        return new JsonResponse(['token' => $jwt]);
    }

    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request, EntityManagerInterface $em, UserRepository $userRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;

        if (!$username || !$password) {
            return new JsonResponse(['error' => 'username and password required'], Response::HTTP_BAD_REQUEST);
        }

        if ($userRepository->findOneBy(['username' => $username])) {
            return new JsonResponse(['error' => 'username already exists'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setUsername($username);
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $user->setPassword($hashed);

        $em->persist($user);
        $em->flush();

        return new JsonResponse(['message' => 'user created'], Response::HTTP_CREATED);
    }
}
