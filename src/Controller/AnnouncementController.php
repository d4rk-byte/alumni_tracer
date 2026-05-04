<?php

namespace App\Controller;

use App\Entity\Announcement;
use App\Form\AnnouncementType;
use App\Repository\AnnouncementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/announcements')]
class AnnouncementController extends AbstractController
{
    #[Route('/', name: 'announcement_index', methods: ['GET'])]
    public function index(AnnouncementRepository $repo): Response
    {
        // Only admins can see inactive/draft announcements in list view.
        if ($this->isGranted('ROLE_ADMIN')) {
            $announcements = $repo->findBy([], ['datePosted' => 'DESC']);
        } else {
            $announcements = $repo->findBy(['isActive' => true], ['datePosted' => 'DESC']);
        }

        return $this->render('announcement/index.html.twig', [
            'announcements' => $announcements,
        ]);
    }

    #[Route('/create', name: 'announcement_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $announcement = new Announcement();
        $form = $this->createForm(AnnouncementType::class, $announcement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->normalizeJoinUrl($announcement);
            $announcement->setPostedBy($this->getUser());
            $em->persist($announcement);
            $em->flush();
            $this->addFlash('success', 'Announcement posted.');

            return $this->redirectToRoute('announcement_index');
        }

        return $this->render('announcement/form.html.twig', [
            'form' => $form->createView(),
            'title' => 'New Announcement',
        ]);
    }

    #[Route('/{id}', name: 'announcement_show', methods: ['GET'])]
    public function show(Announcement $announcement): Response
    {
        if (!$announcement->isActive() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException();
        }

        return $this->render('announcement/show.html.twig', [
            'announcement' => $announcement,
        ]);
    }

    #[Route('/{id}/edit', name: 'announcement_edit', methods: ['GET', 'POST'])]
    public function edit(Announcement $announcement, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(AnnouncementType::class, $announcement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->normalizeJoinUrl($announcement);
            $em->flush();
            $this->addFlash('success', 'Announcement updated.');

            return $this->redirectToRoute('announcement_index');
        }

        return $this->render('announcement/form.html.twig', [
            'form' => $form->createView(),
            'title' => 'Edit Announcement',
        ]);
    }

    #[Route('/{id}/delete', name: 'announcement_delete', methods: ['POST'])]
    public function delete(Announcement $announcement, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if ($this->isCsrfTokenValid('delete' . $announcement->getId(), $request->request->get('_token'))) {
            $em->remove($announcement);
            $em->flush();
            $this->addFlash('success', 'Announcement deleted.');
        }

        return $this->redirectToRoute('announcement_index');
    }

    private function normalizeJoinUrl(Announcement $announcement): void
    {
        $joinUrl = trim((string) $announcement->getJoinUrl());

        if ($joinUrl === '') {
            $announcement->setJoinUrl(null);

            return;
        }

        if (!preg_match('/^[a-z][a-z0-9+.-]*:/i', $joinUrl)) {
            $joinUrl = 'https://' . $joinUrl;
        }

        $announcement->setJoinUrl($joinUrl);
    }
}
