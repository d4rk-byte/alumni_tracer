<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AuditLogger;
use App\Entity\AuditLog;
use App\Service\AccountSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function edit(Request $request, AccountSettingsService $accountSettingsService): Response
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

            $settingsResult = $accountSettingsService->updateSettings($user, [
                'fullName' => trim($request->request->get('firstName', '') . ' ' . $request->request->get('lastName', '')),
                'email' => $request->request->get('email', ''),
            ], $request->getSchemeAndHttpHost());

            if (isset($settingsResult['errors'])) {
                $this->addFlash('danger', reset($settingsResult['errors']) ?: 'Unable to update profile.');
                return $this->redirectToRoute('app_profile_edit');
            }

            $profileImage = $request->files->get('profileImage');
            if ($profileImage instanceof UploadedFile) {
                $photoResult = $accountSettingsService->updatePhoto($user, $profileImage, $request->getSchemeAndHttpHost());

                if (isset($photoResult['errors'])) {
                    $this->addFlash('danger', reset($photoResult['errors']) ?: 'Unable to update profile photo.');
                    return $this->redirectToRoute('app_profile_edit');
                }
            }

            $newPassword = $request->request->get('newPassword', '');
            if ($newPassword !== '') {
                $passwordResult = $accountSettingsService->changePassword($user, [
                    'currentPassword' => $request->request->get('currentPassword', ''),
                    'newPassword' => $newPassword,
                    'confirmPassword' => $request->request->get('confirmPassword', ''),
                ], $request->getSchemeAndHttpHost());

                if (isset($passwordResult['errors'])) {
                    $this->addFlash('danger', reset($passwordResult['errors']) ?: 'Unable to change password.');
                    return $this->redirectToRoute('app_profile_edit');
                }
            }

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
