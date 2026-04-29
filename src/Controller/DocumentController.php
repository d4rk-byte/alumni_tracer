<?php

namespace App\Controller;

use App\Entity\Alumni;
use App\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/documents')]
#[IsGranted('ROLE_USER')]
class DocumentController extends AbstractController
{
    #[Route('/upload/{alumniId}', name: 'document_upload', methods: ['GET', 'POST'])]
    public function upload(
        int $alumniId,
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
    ): Response {
        $alumni = $em->getRepository(Alumni::class)->find($alumniId);
        if (!$alumni) {
            throw $this->createNotFoundException('Alumni not found.');
        }

        // Only owner or staff+ can upload documents
        if (!$this->isGranted('ROLE_STAFF') && $alumni->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You cannot upload documents for this alumni.');
        }

        if ($request->isMethod('POST')) {
            $file = $request->files->get('document');
            $docType = $request->request->get('document_type', 'other');

            if ($file) {
                // Validate file type
                $allowedMimes = [
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                
                ];
                if (!in_array($file->getMimeType(), $allowedMimes, true)) {
                    $this->addFlash('danger', 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG, WEBP.');
                    return $this->redirectToRoute('document_upload', ['alumniId' => $alumniId]);
                }

                // Validate file size (max 10 MB)
                if ($file->getSize() > 10 * 1024 * 1024) {
                    $this->addFlash('danger', 'File too large. Maximum size is 10 MB.');
                    return $this->redirectToRoute('document_upload', ['alumniId' => $alumniId]);
                }

                $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();

                try {
                    $uploadDir = $this->getParameter('kernel.project_dir') . '/var/uploads/documents';
                    $file->move($uploadDir, $newFilename);

                    $document = new Document();
                    $document->setAlumni($alumni);
                    $document->setOriginalFilename($file->getClientOriginalName());
                    $document->setStoredFilename($newFilename);
                    $document->setDocumentType($docType);

                    $em->persist($document);
                    $em->flush();

                    $this->addFlash('success', 'Document uploaded successfully.');
                } catch (FileException $e) {
                    $this->addFlash('danger', 'Upload failed: ' . $e->getMessage());
                }

                return $this->redirectToRoute('alumni_show', ['id' => $alumniId]);
            }

            $this->addFlash('danger', 'Please select a file to upload.');
        }

        return $this->render('document/upload.html.twig', [
            'alumni' => $alumni,
        ]);
    }

    #[Route('/{id}/download', name: 'document_download', methods: ['GET'])]
    public function download(Document $document): Response
    {
        // Only owner or staff+ can download
        $alumni = $document->getAlumni();
        if (!$this->isGranted('ROLE_STAFF') && $alumni->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You cannot download this document.');
        }

        $filePath = $this->getParameter('kernel.project_dir') . '/var/uploads/documents/' . $document->getStoredFilename();

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('File not found.');
        }

        return new BinaryFileResponse($filePath, 200, [
            'Content-Disposition' => 'attachment; filename="' . $document->getOriginalFilename() . '"',
        ]);
    }

    #[Route('/{id}/delete', name: 'document_delete', methods: ['POST'])]
    public function delete(Document $document, Request $request, EntityManagerInterface $em): Response
    {
        $alumni = $document->getAlumni();
        $alumniId = $alumni->getId();

        // Only owner or staff+ can delete documents
        if (!$this->isGranted('ROLE_STAFF') && $alumni->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You cannot delete this document.');
        }

        if ($this->isCsrfTokenValid('delete' . $document->getId(), $request->request->get('_token'))) {
            // Remove file from disk
            $filePath = $this->getParameter('kernel.project_dir') . '/var/uploads/documents/' . $document->getStoredFilename();
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $em->remove($document);
            $em->flush();
            $this->addFlash('success', 'Document deleted.');
        }

        return $this->redirectToRoute('alumni_show', ['id' => $alumniId]);
    }
}
