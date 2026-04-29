<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();

        if ($this->shouldUseAlumniLandingProfileUi()) {
            return $this->redirectToRoute('app_alumni_feature_profile');
        }

        return $this->render('profile/index.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/profile/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, UserRepository $userRepo): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);
        $useAlumniLandingProfileUi = $this->shouldUseAlumniLandingProfileUi();

        if ($request->isMethod('POST')) {
            // Validate CSRF token
            if (!$this->isCsrfTokenValid('profile_edit', $request->request->get('_token'))) {
                $this->addFlash('danger', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('app_profile_edit');
            }

            $firstName = trim($request->request->get('firstName', ''));
            $lastName  = trim($request->request->get('lastName', ''));
            $email     = trim($request->request->get('email', ''));

            if ($firstName === '' || $lastName === '' || $email === '') {
                $this->addFlash('danger', 'First name, last name, and email are required.');
                return $this->redirectToRoute('app_profile_edit');
            }

            // Check email uniqueness
            if ($email !== $user->getEmail()) {
                $existing = $userRepo->findOneBy(['email' => $email]);
                if ($existing) {
                    $this->addFlash('danger', 'This email address is already in use.');
                    return $this->redirectToRoute('app_profile_edit');
                }
            }

            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setEmail($email);

            // Handle profile image upload (optional)
            $profileImage = $request->files->get('profileImage');
            if ($profileImage instanceof UploadedFile && $profileImage->isValid()) {
                $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
                if (!in_array($profileImage->getMimeType(), $allowedMimeTypes, true)) {
                    $this->addFlash('danger', 'Invalid image format. Please upload JPG, PNG, or WEBP.');
                    return $this->redirectToRoute('app_profile_edit');
                }

                if ($profileImage->getSize() !== null && $profileImage->getSize() > 2 * 1024 * 1024) {
                    $this->addFlash('danger', 'Image is too large. Maximum file size is 2MB.');
                    return $this->redirectToRoute('app_profile_edit');
                }

                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/profile';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                $extension = $profileImage->guessExtension() ?: 'jpg';
                $newFilename = sprintf('user_%d_%s.%s', $user->getId(), bin2hex(random_bytes(8)), $extension);
                $profileImage->move($uploadDir, $newFilename);

                $oldImage = $user->getProfileImage();
                if ($oldImage) {
                    $oldPath = $uploadDir . '/' . $oldImage;
                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }

                $user->setProfileImage($newFilename);
            }

            // Handle password change (optional)
            $newPassword = $request->request->get('newPassword', '');
            $confirmPassword = $request->request->get('confirmPassword', '');
            $currentPassword = $request->request->get('currentPassword', '');

            if ($newPassword !== '') {
                // Require current password
                if ($currentPassword === '' || !$hasher->isPasswordValid($user, $currentPassword)) {
                    $this->addFlash('danger', 'Current password is incorrect.');
                    return $this->redirectToRoute('app_profile_edit');
                }
                if (strlen($newPassword) < 8) {
                    $this->addFlash('danger', 'Password must be at least 8 characters.');
                    return $this->redirectToRoute('app_profile_edit');
                }
                if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).+$/', $newPassword)) {
                    $this->addFlash('danger', 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.');
                    return $this->redirectToRoute('app_profile_edit');
                }
                if ($newPassword !== $confirmPassword) {
                    $this->addFlash('danger', 'Passwords do not match.');
                    return $this->redirectToRoute('app_profile_edit');
                }
                // Prevent reuse of current password
                if ($hasher->isPasswordValid($user, $newPassword)) {
                    $this->addFlash('danger', 'New password must be different from your current password.');
                    return $this->redirectToRoute('app_profile_edit');
                }
                $user->setPassword($hasher->hashPassword($user, $newPassword));
            }

            $em->flush();
            $this->addFlash('success', 'Profile updated successfully.');
            return $this->redirectToRoute($useAlumniLandingProfileUi ? 'app_alumni_feature_profile' : 'app_profile');
        }

        if ($useAlumniLandingProfileUi) {
            return $this->render('home/alumni_profile_edit_page.html.twig', [
                'user' => $user,
                'alumni' => $user->getAlumni(),
                'landing_mode' => 'alumni',
                'profileSnapshot' => [
                    'completionPercent' => 0,
                    'accountStatus' => ucfirst($user->getAccountStatus()),
                ],
            ]);
        }

        return $this->render('profile/edit.html.twig', [
            'user' => $user,
        ]);
    }

    private function shouldUseAlumniLandingProfileUi(): bool
    {
        return $this->isGranted(User::ROLE_ALUMNI)
            && !$this->isGranted('ROLE_STAFF')
            && !$this->isGranted('ROLE_ADMIN');
    }

    #[Route('/profile/erase', name: 'app_profile_erase', methods: ['POST'])]
    public function eraseData(Request $request, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        if (!$this->isCsrfTokenValid('erase_data', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        assert($user instanceof User);

        $audit->log(
            AuditLog::ACTION_DELETE_USER,
            'User',
            $user->getId(),
            'User requested data erasure (DPA): ' . $user->getFullName() . ' (' . $user->getEmail() . ')'
        );

        // Soft-delete alumni record if exists
        $alumni = $user->getAlumni();
        if ($alumni) {
            $alumni->setDeletedAt(new \DateTime());
        }

        // Anonymize user data
        $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/profile';
        if ($user->getProfileImage()) {
            $imagePath = $uploadDir . '/' . $user->getProfileImage();
            if (is_file($imagePath)) {
                @unlink($imagePath);
            }
        }

        $user->setFirstName('Deleted');
        $user->setLastName('User');
        $user->setEmail('deleted_' . $user->getId() . '@removed.local');
        $user->setAccountStatus('inactive');
        $user->setPassword('');
        $user->setDpaConsent(false);
        $user->setProfileImage(null);

        $em->flush();

        // Invalidate session
        $request->getSession()->invalidate();
        $this->container->get('security.token_storage')->setToken(null);

        return $this->redirectToRoute('app_login');
    }
}
