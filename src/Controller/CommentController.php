<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Form\CommentType;
use App\Repository\CommentRepository;
use App\Repository\PostRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/comment')]
class CommentController extends AbstractController
{
    #[Route('/', name: 'app_comment_index', methods: ['GET'])]
    public function index(CommentRepository $commentRepository): Response
    {
        return $this->render('comment/index.html.twig', [
            'comments' => $commentRepository->findAll(),
        ]);
    }

    #[Route('/{id}', name: 'app_comment_show', methods: ['GET'])]
    public function show(Comment $comment): Response
    {
        return $this->render('comment/show.html.twig', [
            'comment' => $comment,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_comment_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Comment $comment, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_comment_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('comment/edit.html.twig', [
            'comment' => $comment,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_comment_delete', methods: ['POST'])]
    public function delete(Request $request, Comment $comment, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $comment->getId(), $request->request->get('_token'))) {
            $entityManager->remove($comment);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_comment_index', [], Response::HTTP_SEE_OTHER);
    }

    /** 
     * Función que permite crear un nuevo comentario con la información
     * recibida desde el formulario del frontend.
     */
    #[Route('/api/new', name: 'api_comment_new', methods: ['POST'])]
    public function newComment(Request $request, EntityManagerInterface $entityManager, UserRepository $userRepository, PostRepository $postRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $text = $data['text'] ?? null;
        $authorId = $data['author'] ?? null;
        $postId = $data['post'] ?? null;

        if (!$text || !$authorId || !$postId) {
            return new JsonResponse(['error' => 'Missing required fields (text, author, post)'], Response::HTTP_BAD_REQUEST);
        }

        $author = $userRepository->find($authorId);
        if (!$author) {
            return new JsonResponse(['error' => 'Author not found'], Response::HTTP_NOT_FOUND);
        }

        $post = $postRepository->find($postId);
        if (!$post) {
            return new JsonResponse(['error' => 'Post not found'], Response::HTTP_NOT_FOUND);
        }

        $comment = new Comment();
        $comment->setText($text);
        $comment->setAuthor($author);
        $comment->setPost($post);
        $comment->setStatus(0);

        $entityManager->persist($comment);
        $entityManager->flush();

        return new JsonResponse([
            'message' => 'Comment created successfully',
            'comment_id' => $comment->getId()
        ], Response::HTTP_CREATED);
    }

    /** 
     * Función que permite listar todos los comentarios
     * registrados en la base de datos.
     */
    #[Route('/api/all', name: 'api_comment_all', methods: ['GET'])]
    public function showComment(CommentRepository $commentRepository): JsonResponse
    {
        $comments = $commentRepository->findAll();
        $data = [];

        foreach ($comments as $comment) {
            $data[] = [
                'id' => $comment->getId(),
                'text' => $comment->getText(),
                'author' => $comment->getAuthor() ? $comment->getAuthor()->getUsername() : 'Anonymous',
                'post' => $comment->getPost() ? $comment->getPost()->getId() : null,
                'status' => $comment->getStatus(),
            ];
        }

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /** 
     * Función que permite banear un comentario sin eliminar
     * su información desde la base de datos.
     */
    #[Route('/{id}/ban', name: 'api_comment_ban', methods: ['POST'])]
    public function eliminarComment(int $id, CommentRepository $commentRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $comment = $commentRepository->find($id);

        if (!$comment) {
            return new JsonResponse(['error' => 'Comment not found'], Response::HTTP_NOT_FOUND);
        }

        $status = $comment->getStatus() ?? 0;
        if ($status === 1) {
            $comment->setStatus(0);
        } else {
            $comment->setStatus(1);
        }

        $entityManager->persist($comment);
        $entityManager->flush();

        return new JsonResponse(['id' => $comment->getId(), 'status' => $comment->getStatus()]);
    }
}
