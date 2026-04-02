<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user')]
class UserController extends AbstractController
{
    #[Route('/', name: 'app_user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($user);
            $entityManager->flush();

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/new.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_show', methods: ['GET'])]
    public function show(User $user): Response
    {
        return $this->render('user/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            $entityManager->remove($user);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
    }

    /** 
     * Función que permite cambiar el estado de un usuario
     * (banear o desbanear).
     */
    #[Route('/{id}/change-status', name: 'api_user_change_status', methods: ['POST'])]
    public function changeStatus(int $id, UserRepository $userRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $userRepository->find($id);

        if (!$user) {
            return new JsonResponse(['error' => 'user not found'], Response::HTTP_NOT_FOUND);
        }

        $status = $user->getStatus() ?? 0;
        if ($status === 1) {
            $user->setStatus(0);
        } else {
            $user->setStatus(1);
        }

        $entityManager->persist($user);
        $entityManager->flush();

        return new JsonResponse(['id' => $user->getId(), 'status' => $user->getStatus()]);
    }

    /** 
     * Función que permite a un usuario seguir o dejar de seguir a otro.
     */
    #[Route('/api/follow', name: 'api_user_follow', methods: ['POST'])]
    public function followUser(Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $followerId = $data['followerId'] ?? null;
        $followedId = $data['followedId'] ?? null;
        $action = $data['action'] ?? null; // Espera 'follow' o 'unfollow'

        if (!$followerId || !$followedId || !$action) {
            return new JsonResponse(['error' => 'followerId, followedId and action are required'], Response::HTTP_BAD_REQUEST);
        }

        $follower = $userRepository->find($followerId);
        $followed = $userRepository->find($followedId);

        if (!$follower || !$followed) {
            return new JsonResponse(['error' => 'user not found'], Response::HTTP_NOT_FOUND);
        }

        $currentFollows = $follower->getFollows() ?? 0;
        $currentFollowers = $followed->getFollowers() ?? 0;

        if ($action === 'follow') {
            $follower->setFollows($currentFollows + 1);
            $followed->setFollowers($currentFollowers + 1);
        } elseif ($action === 'unfollow') {
            $follower->setFollows(max(0, $currentFollows - 1));
            $followed->setFollowers(max(0, $currentFollowers - 1));
        } else {
            return new JsonResponse(['error' => 'Invalid action. Must be "follow" or "unfollow"'], Response::HTTP_BAD_REQUEST);
        }

        $entityManager->persist($follower);
        $entityManager->persist($followed);
        $entityManager->flush();

        return new JsonResponse([
            'message' => 'Action handled successfully',
            'follower_follows' => $follower->getFollows(),
            'followed_followers' => $followed->getFollowers()
        ], Response::HTTP_OK);
    }
}
