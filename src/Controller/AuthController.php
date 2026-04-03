<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AuthController extends AbstractController
{
    /** 
     * Función que permite al frontend hacer el inicio de sesión
     * para cada usuario registrado en la base de datos.
     * 
     * Ademas permite tener la sesión iniciada durante una hora.
     */
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
        $exp = $now + 3600;
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

    /** 
     * Función que permite registrar un nuevo usuario en la base de datos
     * recibiendo la información de este mediante un formulario en el frontend.
     */
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
        // Se asegura de que los nuevos usuarios tengan el rol ROLE_USER
        $user->setRoles(['ROLE_USER']);
        $user->setStatus(0);
        $user->setFollowers(0);
        $user->setFollows(0);

        $em->persist($user);
        $em->flush();

        return new JsonResponse(['message' => 'user created'], Response::HTTP_CREATED);
    }

    /** 
     * Función que permite cambiar el rol de un usuario.
     */
    #[Route('/user/{id}/change-role', name: 'api_change_role', methods: ['POST'])]
    public function changeRole(int $id, UserRepository $userRepository, EntityManagerInterface $em): JsonResponse
    {
        $user = $userRepository->find($id);

        if (!$user) {
            return new JsonResponse(['error' => 'user not found'], Response::HTTP_NOT_FOUND);
        }

        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true)) {
            $user->setRoles(['ROLE_USER']);
        } else {
            $user->setRoles(['ROLE_ADMIN']);
        }

        $em->persist($user);
        $em->flush();

        return new JsonResponse(['id' => $user->getId(), 'roles' => $user->getRoles()]);
    }

    /** 
     * Función que permite listar toda la información de los usuarios 
     * registrados en la base de datos.
     */
    #[Route('/users', name: 'api_users', methods: ['GET'])]
    public function listUsers(UserRepository $userRepository): JsonResponse
    {
        $users = $userRepository->findAll();

        $data = array_map(function (User $u) {
            return [
                'id' => $u->getId(),
                'username' => $u->getUserIdentifier(),
                'roles' => $u->getRoles(),
                'status' => $u->getStatus(),
                'posts' => array_map(fn($p) => $p->getId(), $u->getPosts()->toArray()),
                'comments' => array_map(fn($c) => $c->getId(), $u->getComments()->toArray()),
                'followers' => $u->getFollowers(),
                'follows' => $u->getFollows(),
            ];
        }, $users);

        return new JsonResponse($data);
    }

    /** 
     * Función que permite buscar usuarios o publicaciones en la base de datos.
     * La petición se hará mediante POST o GET y recibirá 'type' (user o post) y 'query'.
     */
    #[Route('/search', name: 'api_search', methods: ['POST'])]
    public function search(Request $request, UserRepository $userRepository, PostRepository $postRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $type = $data['type'] ?? $request->query->get('type');
        $query = $data['query'] ?? $request->query->get('query');

        if (!$type || !$query) {
            return new JsonResponse(['error' => 'type and query are required'], Response::HTTP_BAD_REQUEST);
        }

        if ($type === 'user') {
            $qb = $userRepository->createQueryBuilder('u');
            $users = $qb->where('u.username LIKE :query')
                        ->setParameter('query', '%' . $query . '%')
                        ->getQuery()
                        ->getResult();

            $result = array_map(function (User $u) {
                return [
                    'id' => $u->getId(),
                    'username' => $u->getUserIdentifier(),
                    'roles' => $u->getRoles(),
                    'status' => $u->getStatus(),
                    'posts' => array_map(fn($p) => $p->getId(), $u->getPosts()->toArray()),
                    'comments' => array_map(fn($c) => $c->getId(), $u->getComments()->toArray()),
                    'followers' => $u->getFollowers(),
                    'follows' => $u->getFollows(),
                ];
            }, $users);

            return new JsonResponse($result);
        } elseif ($type === 'post') {
            $qb = $postRepository->createQueryBuilder('p');
            $posts = $qb->where('p.title LIKE :query')
                        ->setParameter('query', '%' . $query . '%')
                        ->getQuery()
                        ->getResult();

            $result = array_map(function ($post) {
                return [
                    'id' => $post->getId(),
                    'title' => $post->getTitle(),
                    'description' => $post->getText(),
                    'img_video' => $post->getImgVideo(),
                    'author' => $post->getAuthor() ? $post->getAuthor()->getUsername() : 'Anonymous',
                    'date' => $post->getDate() ? $post->getDate()->format('Y-m-d H:i:s') : null,
                    'likes' => $post->getLikes(),
                    'dislikes' => $post->getDislikes(),
                    'status' => $post->getStatus(),
                ];
            }, $posts);

            return new JsonResponse($result);
        }

        return new JsonResponse(['error' => 'invalid search type'], Response::HTTP_BAD_REQUEST);
    }
}
