<?php

namespace App\Controller;

use App\Entity\Post;
use App\Form\PostType;
use App\Repository\PostRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/post')]
class PostController extends AbstractController
{
    #[Route('/', name: 'app_post_index', methods: ['GET'])]
    public function index(PostRepository $postRepository): Response
    {
        return $this->render('post/index.html.twig', [
            'posts' => $postRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_post_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $post = new Post();
        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($post);
            $entityManager->flush();

            return $this->redirectToRoute('app_post_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('post/new.html.twig', [
            'post' => $post,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_post_show', methods: ['GET'])]
    public function show(Post $post): Response
    {
        return $this->render('post/show.html.twig', [
            'post' => $post,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_post_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Post $post, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(PostType::class, $post);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_post_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('post/edit.html.twig', [
            'post' => $post,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_post_delete', methods: ['POST'])]
    public function delete(Request $request, Post $post, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$post->getId(), $request->request->get('_token'))) {
            $entityManager->remove($post);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_post_index', [], Response::HTTP_SEE_OTHER);
    }
    #[Route('/api/new', name: 'api_post_new', methods: ['POST'])]
    public function newPost(Request $request, EntityManagerInterface $entityManager, UserRepository $userRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $title = $data['title'] ?? null;
        $text = $data['description'] ?? null;
        $imgVideo = $data['img_video'] ?? null;
        $authorId = $data['author'] ?? null;

        if (!$title || !$text || !$authorId) {
            return new JsonResponse(['error' => 'Missing required fields (title, description, author)'], Response::HTTP_BAD_REQUEST);
        }

        $author = $userRepository->find($authorId);
        if (!$author) {
            return new JsonResponse(['error' => 'Author not found'], Response::HTTP_NOT_FOUND);
        }

        $post = new Post();
        $post->setTitle($title);
        $post->setText($text);
        
        // Si img_video contiene base64, lo decodificamos y guardamos el archivo
        if ($imgVideo && preg_match('/^data:(image|video)\/(\w+);base64,/', $imgVideo, $type)) {
            $dataEncoded = substr($imgVideo, strpos($imgVideo, ',') + 1);
            $dataDecoded = base64_decode($dataEncoded);
            $extension = strtolower($type[2]);
            $fileName = uniqid() . '.' . $extension;
            
            $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/posts';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            file_put_contents($uploadDir . '/' . $fileName, $dataDecoded);
            $post->setImgVideo('/uploads/posts/' . $fileName);
        } else {
            // Si el frontend sube el archivo por otro medio y envía su nombre, o si está vacío
            $post->setImgVideo($imgVideo);
        }

        $post->setAuthor($author);
        $post->setDate(new \DateTime());
        $post->setLikes(0);
        $post->setDislikes(0);
        $post->setStatus(0);

        $entityManager->persist($post);
        $entityManager->flush();

        return new JsonResponse([
            'message' => 'Post created successfully', 
            'post_id' => $post->getId()
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/all', name: 'api_post_all', methods: ['GET'])]
    public function showPost(PostRepository $postRepository): JsonResponse
    {
        $posts = $postRepository->findAll();
        $data = [];

        foreach ($posts as $post) {
            $data[] = [
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
        }

        return new JsonResponse($data, Response::HTTP_OK);
    }

    #[Route('/api/like_dislike/{id}', name: 'api_post_like_dislike', methods: ['POST'])]
    public function like_dislike(int $id, Request $request, EntityManagerInterface $entityManager, PostRepository $postRepository): JsonResponse
    {
        $post = $postRepository->find($id);

        if (!$post) {
            return new JsonResponse(['error' => 'Post not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        
        // "La informacion de si ha dado a like o dislike le llegara por el front"
        $action = $data['action'] ?? null; // 'like' or 'dislike'
        $previousStatus = $data['previous_status'] ?? 'none'; // 'none', 'like', 'dislike'

        if ($action === 'like') {
            if ($previousStatus === 'dislike') {
                $post->setLikes($post->getLikes() + 1);
                $post->setDislikes(max(0, $post->getDislikes() - 1));
            } elseif ($previousStatus === 'none') {
                $post->setLikes($post->getLikes() + 1);
            } elseif ($previousStatus === 'like') {
                // Si vuelve a darle a like y ya le había dado, se lo quitamos
                $post->setLikes(max(0, $post->getLikes() - 1));
            }
        } elseif ($action === 'dislike') {
            if ($previousStatus === 'like') {
                $post->setDislikes($post->getDislikes() + 1);
                $post->setLikes(max(0, $post->getLikes() - 1));
            } elseif ($previousStatus === 'none') {
                $post->setDislikes($post->getDislikes() + 1);
            } elseif ($previousStatus === 'dislike') {
                // Si vuelve a darle a dislike y ya le había dado, se lo quitamos
                $post->setDislikes(max(0, $post->getDislikes() - 1));
            }
        }

        $entityManager->persist($post);
        $entityManager->flush();

        return new JsonResponse([
            'message' => 'Action processed successfully',
            'likes' => $post->getLikes(),
            'dislikes' => $post->getDislikes()
        ], Response::HTTP_OK);
    }
}
