<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
class AdminController extends AbstractController
{
    // ── Users Management ──

    #[Route('/users/create-staff', name: 'app_staff_create', methods: ['GET'])]
    #[Route('/staff/new', name: 'app_staff_new', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createStaffShortcut(): Response
    {
        $this->addFlash('info', 'Staff creation form is not yet configured. You can promote an existing account from Manage Users.');

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/create-alumni', name: 'app_alumni_create', methods: ['GET'])]
    #[Route('/alumni/new', name: 'app_alumni_new', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createAlumniShortcut(): Response
    {
        return $this->redirectToRoute('alumni_create');
    }

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function users(Request $request, UserRepository $repo): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $roleFilter = trim((string) $request->query->get('role', ''));
        $selectedCollege = trim((string) $request->query->get('college', ''));
        $selectedDepartment = trim((string) $request->query->get('department', ''));
        $selectedBatch = trim((string) $request->query->get('batch', ''));

        $qb = $repo->createQueryBuilder('u')
            ->leftJoin('u.alumni', 'a')
            ->addSelect('a');

        if ($roleFilter === 'staff') {
            $qb->andWhere($repo->createRoleMatchExpression($qb, 'u', User::ROLE_CODE_STAFF, 'staff_role'));
        }

        /** @var list<User> $users */
        $users = $qb
            ->orderBy('u.dateRegistered', 'DESC')
            ->getQuery()
            ->getResult();

        $collegeOptions = [];
        $departmentOptions = [];
        $departmentCollegeMap = [];
        $batchOptions = [];

        foreach ($users as $user) {
            $alumni = $user->getAlumni();
            if ($alumni === null) {
                continue;
            }

            $college = trim((string) ($alumni->getCollege() ?? ''));
            if ($college !== '') {
                $collegeOptions[$college] = $college;
            }

            $department = trim((string) ($alumni->getDegreeProgram() ?? ''));
            if ($department === '') {
                $department = trim((string) ($alumni->getCourse() ?? ''));
            }

            if ($department !== '') {
                $departmentOptions[$department] = $department;

                if ($college !== '' && !isset($departmentCollegeMap[$department])) {
                    $departmentCollegeMap[$department] = $college;
                }
            }

            $batch = $alumni->getYearGraduated();
            if ($batch !== null) {
                $batchOptions[(string) $batch] = $batch;
            }
        }

        $collegeOptions = array_values($collegeOptions);
        natcasesort($collegeOptions);
        $collegeOptions = array_values($collegeOptions);

        $departmentOptions = array_values($departmentOptions);
        natcasesort($departmentOptions);
        $departmentOptions = array_values($departmentOptions);

        $batchOptions = array_values($batchOptions);
        rsort($batchOptions, SORT_NUMERIC);

        $pendingCount = $repo->count(['accountStatus' => 'pending']);

        return $this->render('admin/users.html.twig', [
            'users' => $users,
            'pendingCount' => $pendingCount,
            'search' => $search,
            'roleFilter' => $roleFilter,
            'collegeOptions' => $collegeOptions,
            'departmentOptions' => $departmentOptions,
            'departmentCollegeMap' => $departmentCollegeMap,
            'batchOptions' => $batchOptions,
            'selectedCollege' => $selectedCollege,
            'selectedDepartment' => $selectedDepartment,
            'selectedBatch' => $selectedBatch,
        ]);
    }

    #[Route('/users/{id}/approve', name: 'admin_user_approve', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approveUser(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('approve' . $user->getId(), $request->request->get('_token'))) {
            $user->setAccountStatus('active');
            $em->flush();
            $this->addFlash('success', $user->getFullName() . ' has been approved.');
        }

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/toggle-status', name: 'admin_user_toggle_status', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleUserStatus(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('toggle' . $user->getId(), $request->request->get('_token'))) {
            $newStatus = $user->getAccountStatus() === 'active' ? 'inactive' : 'active';
            $user->setAccountStatus($newStatus);
            $em->flush();
            $this->addFlash('success', $user->getFullName() . ' status changed to ' . $newStatus . '.');
        }

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/toggle-admin', name: 'admin_user_toggle_admin', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function toggleAdmin(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('admin' . $user->getId(), $request->request->get('_token'))) {
            $roles = $user->getRoles();
            if (in_array('ROLE_ADMIN', $roles)) {
                $user->setRoles([]);
            } else {
                if ($user->getAlumni() !== null || in_array(User::ROLE_ALUMNI, $roles, true)) {
                    $this->addFlash('danger', 'Cannot assign ROLE_ADMIN to an alumni account.');
                    return $this->redirectToRoute('admin_users');
                }
                $user->setRoles(['ROLE_ADMIN']);
            }
            $em->flush();
            $this->addFlash('success', $user->getFullName() . ' role updated.');
        }

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function editUser(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $currentUser = $this->getUser();
        $originalRoles = $user->getRoles();

        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedRole = $form->get('roles')->getData();
            if (is_string($selectedRole) && $selectedRole !== '') {
                // Ensure Doctrine receives roles as an array when dropdown returns a string.
                $user->setRoles([$selectedRole]);
            } elseif (is_array($selectedRole)) {
                $user->setRoles($selectedRole);
            }

            if ($currentUser instanceof User && $currentUser->getId() === $user->getId() && $originalRoles !== $user->getRoles()) {
                $user->setRoles($originalRoles);
                $this->addFlash('danger', 'You cannot change your own role.');

                return $this->redirectToRoute('admin_user_edit', ['id' => $user->getId()]);
            }

            try {
                $em->flush();
                $this->addFlash('success', 'User account updated successfully.');

                return $this->redirectToRoute('admin_users');
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Database error while saving user: ' . $e->getMessage());
            }
        } elseif ($form->isSubmitted() && !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', 'Validation error: ' . $error->getMessage());
            }
        }

        return $this->render('admin/user_edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/users/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteUser(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            $currentUser = $this->getUser();

            if ($user === $currentUser) {
                $this->addFlash('danger', 'You cannot delete your own account.');
                return $this->redirectToRoute('admin_users');
            }

            if (!$currentUser instanceof User) {
                $this->addFlash('danger', 'Unable to verify current admin account.');
                return $this->redirectToRoute('admin_users');
            }

            try {
                // Prevent FK violations by transferring historical audit ownership
                // to the acting admin before deleting the target account.
                $em->createQuery('UPDATE App\\Entity\\AuditLog a SET a.performedBy = :replacement WHERE a.performedBy = :target')
                    ->setParameter('replacement', $currentUser)
                    ->setParameter('target', $user)
                    ->execute();

                $em->remove($user);
                $em->flush();
                $this->addFlash('success', 'User deleted.');
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Unable to delete user: ' . $e->getMessage());
            }
        }

        return $this->redirectToRoute('admin_users');
    }

    // ── Users Export ──

    #[Route('/users/export', name: 'admin_users_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function usersExport(UserRepository $repo): StreamedResponse
    {
        $users = $repo->findBy([], ['dateRegistered' => 'DESC']);

        $response = new StreamedResponse(function () use ($users) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Name', 'Email', 'Role', 'Status', 'Date Registered', 'Last Login']);
            foreach ($users as $u) {
                $role = in_array('ROLE_ADMIN', $u->getRoles()) ? 'Admin' :
                       (in_array('ROLE_STAFF', $u->getRoles()) ? 'Staff' : 'Alumni');
                fputcsv($handle, [
                    $u->getFullName(),
                    $u->getEmail(),
                    $role,
                    $u->getAccountStatus(),
                    $u->getDateRegistered()?->format('Y-m-d'),
                    $u->getLastLogin()?->format('Y-m-d H:i'),
                ]);
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="users_' . date('Ymd') . '.csv"');

        return $response;
    }

}
