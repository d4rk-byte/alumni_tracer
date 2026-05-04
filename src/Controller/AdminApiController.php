<?php

namespace App\Controller;

use App\Entity\Alumni;
use App\Entity\Announcement;
use App\Entity\AuditLog;
use App\Entity\College;
use App\Entity\Department;
use App\Entity\GtsSurvey;
use App\Entity\GtsSurveyQuestion;
use App\Entity\GtsSurveyTemplate;
use App\Entity\JobPosting;
use App\Entity\Notification as AdminNotification;
use App\Entity\QrRegistrationBatch;
use App\Entity\SurveyCampaign;
use App\Entity\SurveyInvitation;
use App\Entity\User;
use App\Repository\AlumniRepository;
use App\Repository\AnnouncementRepository;
use App\Repository\AuditLogRepository;
use App\Repository\CollegeRepository;
use App\Repository\DepartmentRepository;
use App\Repository\GtsSurveyQuestionRepository;
use App\Repository\GtsSurveyRepository;
use App\Repository\GtsSurveyTemplateRepository;
use App\Repository\JobPostingRepository;
use App\Repository\QrRegistrationBatchRepository;
use App\Repository\SurveyCampaignRepository;
use App\Repository\SurveyInvitationRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\GtsSurveyQuestionBank;
use App\Service\GtsSurveyAnalyticsService;
use App\Service\NotificationService;
use App\Service\SurveyCampaignDispatchService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminApiController extends AbstractController
{
    #[Route('/features', name: 'api_admin_features', methods: ['GET'])]
    public function features(): JsonResponse
    {
        return $this->json([
            'features' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'endpoint' => '/api/admin/dashboard', 'methods' => ['GET']],
                ['key' => 'users', 'label' => 'Manage Users', 'endpoint' => '/api/admin/users', 'methods' => ['GET']],
                ['key' => 'verification', 'label' => 'Profile Verification', 'endpoint' => '/api/admin/verification', 'methods' => ['GET', 'POST actions']],
                ['key' => 'announcements', 'label' => 'Announcements', 'endpoint' => '/api/admin/announcements', 'methods' => ['GET']],
                ['key' => 'jobs', 'label' => 'Job Postings', 'endpoint' => '/api/admin/jobs', 'methods' => ['GET']],
                ['key' => 'academic', 'label' => 'Academic Management', 'endpoint' => '/api/admin/academic', 'methods' => ['GET']],
                ['key' => 'qr_registration', 'label' => 'QR Registration', 'endpoint' => '/api/admin/qr-registration', 'methods' => ['GET', 'POST actions']],
                ['key' => 'gts', 'label' => 'GTS Surveys and Campaigns', 'endpoint' => '/api/admin/gts/surveys', 'methods' => ['GET', 'POST actions']],
                ['key' => 'audit_logs', 'label' => 'Audit Logs', 'endpoint' => '/api/admin/audit-logs', 'methods' => ['GET']],
            ],
        ]);
    }

    #[Route('/dashboard', name: 'api_admin_dashboard', methods: ['GET'])]
    public function dashboard(
        AlumniRepository $alumniRepo,
        UserRepository $userRepo,
        JobPostingRepository $jobRepo,
        AnnouncementRepository $announcementRepo,
        GtsSurveyRepository $gtsRepo,
        GtsSurveyAnalyticsService $gtsAnalytics,
        AuditLogRepository $auditLogRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $totalAlumni = $alumniRepo->count([]);
        $employed = $alumniRepo->count(['employmentStatus' => 'Employed']);
        $unemployed = $alumniRepo->count(['employmentStatus' => 'Unemployed']);
        $selfEmployed = $alumniRepo->count(['employmentStatus' => 'Self-Employed']);
        $totalUsers = $userRepo->count([]);
        $employedTotal = $employed + $selfEmployed;
        $jobRelated = $alumniRepo->count(['jobRelatedToCourse' => true]);
        $threshold = (new \DateTime())->modify('-5 minutes');
        $activeUsers = (int) $em->createQuery(
            'SELECT COUNT(u.id) FROM App\\Entity\\User u WHERE u.lastActivity IS NOT NULL AND u.lastActivity > :threshold'
        )->setParameter('threshold', $threshold)->getSingleScalarResult();
        $surveyAnalytics = $gtsAnalytics->summarize();

        $recentAnnouncements = $announcementRepo->findBy([], ['datePosted' => 'DESC'], 5);

        return $this->json([
            'stats' => [
                'totalAlumni' => $totalAlumni,
                'employed' => $employed,
                'unemployed' => $unemployed,
                'selfEmployed' => $selfEmployed,
                'totalUsers' => $totalUsers,
                'pendingUsers' => $userRepo->count(['accountStatus' => 'pending']),
                'activeUsers' => $activeUsers,
                'adminUsers' => $userRepo->countUsersWithRole('ROLE_ADMIN'),
                'staffUsers' => $userRepo->countUsersWithRole('ROLE_STAFF'),
                'activeJobs' => $jobRepo->count(['isActive' => true]),
                'totalJobs' => $jobRepo->count([]),
                'activeAnnouncements' => $announcementRepo->count(['isActive' => true]),
                'totalSurveyResponses' => $gtsRepo->count([]),
                'surveyEmploymentRate' => $surveyAnalytics['employmentRate'],
                'surveyAlignmentRate' => $surveyAnalytics['courseAlignmentRate'],
                'employmentRate' => $totalAlumni > 0 ? round(($employedTotal / $totalAlumni) * 100, 1) : 0,
                'alignmentRate' => $employedTotal > 0 ? round(($jobRelated / $employedTotal) * 100, 1) : 0,
            ],
            'registrationStates' => $alumniRepo->countRegistrationStates(),
            'employmentStatusChart' => $alumniRepo->countGroupedByEmploymentStatus(),
            'surveyAnalytics' => $surveyAnalytics,
            'recentAnnouncements' => array_map(fn (Announcement $announcement): array => $this->serializeAnnouncement($announcement), $recentAnnouncements),
            'recentAuditLogs' => array_map(fn (AuditLog $log): array => $this->serializeAuditLog($log), $auditLogRepo->findRecent(10)),
        ]);
    }

    #[Route('/notifications', name: 'api_admin_notifications', methods: ['GET'])]
    public function notifications(Request $request, NotificationService $notifications): JsonResponse
    {
        $user = $this->currentUser();
        $limit = min($this->positiveInt($request->query->get('limit'), 20), 100);

        return $this->json([
            'items' => array_map(
                fn (AdminNotification $notification): array => $notifications->serialize($notification),
                $notifications->recentFor($user, $limit)
            ),
            'unreadCount' => $notifications->unreadCountFor($user),
        ]);
    }

    #[Route('/notifications/unread-count', name: 'api_admin_notifications_unread_count', methods: ['GET'])]
    public function notificationUnreadCount(NotificationService $notifications): JsonResponse
    {
        return $this->json([
            'unreadCount' => $notifications->unreadCountFor($this->currentUser()),
        ]);
    }

    #[Route('/notifications/{id}/read', name: 'api_admin_notification_read', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function readNotification(AdminNotification $notification, NotificationService $notifications): JsonResponse
    {
        $user = $this->currentUser();

        if (!$notifications->markRead($notification, $user)) {
            return $this->json(['error' => 'Notification not found.'], 404);
        }

        return $this->json([
            'item' => $notifications->serialize($notification),
            'unreadCount' => $notifications->unreadCountFor($user),
        ]);
    }

    #[Route('/notifications/read-all', name: 'api_admin_notifications_read_all', methods: ['PATCH'])]
    public function readAllNotifications(NotificationService $notifications): JsonResponse
    {
        $user = $this->currentUser();

        return $this->json([
            'updated' => $notifications->markAllRead($user),
            'unreadCount' => $notifications->unreadCountFor($user),
        ]);
    }

    #[Route('/notifications/stream', name: 'api_admin_notifications_stream', methods: ['GET'])]
    public function notificationStream(Request $request, NotificationService $notifications): StreamedResponse
    {
        $user = $this->currentUser();
        $sinceId = $this->nonNegativeInt($request->query->get('since'), 0);

        $response = new StreamedResponse(function () use ($user, $notifications, $sinceId): void {
            $lastId = $sinceId;
            $lastCount = null;
            $endsAt = time() + 25;

            while (time() < $endsAt && !connection_aborted()) {
                foreach ($notifications->newFor($user, $lastId, 50) as $notification) {
                    $lastId = max($lastId, (int) $notification->getId());
                    $payload = [
                        'item' => $notifications->serialize($notification),
                        'unreadCount' => $notifications->unreadCountFor($user),
                    ];

                    echo "id: {$lastId}\n";
                    echo "event: notification.created\n";
                    echo 'data: ' . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
                }

                $unreadCount = $notifications->unreadCountFor($user);
                if ($lastCount !== $unreadCount) {
                    $lastCount = $unreadCount;
                    echo "event: notification.count\n";
                    echo 'data: ' . json_encode(['unreadCount' => $unreadCount], JSON_THROW_ON_ERROR) . "\n\n";
                }

                @ob_flush();
                flush();
                sleep(2);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    #[Route('/users', name: 'api_admin_users', methods: ['GET'])]
    public function users(
        Request $request,
        UserRepository $userRepo,
        GtsSurveyRepository $surveyRepo,
        GtsSurveyQuestionBank $questionBank,
    ): JsonResponse
    {
        $page = $this->positiveInt($request->query->get('page'), 1);
        $limit = min($this->positiveInt($request->query->get('limit'), 25), 100);
        $search = trim((string) $request->query->get('q', ''));
        $status = trim((string) $request->query->get('status', ''));
        $role = trim((string) $request->query->get('role', ''));

        $qb = $userRepo->createQueryBuilder('u')
            ->leftJoin('u.alumni', 'a')
            ->addSelect('a')
            ->orderBy('u.dateRegistered', 'DESC');

        if ($search !== '') {
            $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR u.email LIKE :q OR u.schoolId LIKE :q')
                ->setParameter('q', '%' . $search . '%');
        }

        if ($status !== '') {
            $qb->andWhere('u.accountStatus = :status')
                ->setParameter('status', $status);
        }

        if ($role !== '') {
            $roleName = match (strtolower($role)) {
                'admin' => 'ROLE_ADMIN',
                'staff' => 'ROLE_STAFF',
                'alumni', 'user' => User::ROLE_ALUMNI,
                default => strtoupper($role),
            };
            $qb->andWhere($userRepo->createRoleMatchExpression($qb, 'u', $roleName, 'api_role'));
        }

        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        $paginator = new Paginator($qb);

        return $this->json([
            'items' => array_map(
                fn (User $user): array => $this->serializeUser($user, false, $surveyRepo, $questionBank),
                iterator_to_array($paginator)
            ),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => count($paginator),
            ],
        ]);
    }

    #[Route('/users', name: 'api_admin_user_create', methods: ['POST'])]
    public function createUser(
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        AuditLogger $audit,
    ): JsonResponse {
        $payload = $this->jsonPayload($request);
        $firstName = trim((string) ($payload['firstName'] ?? ''));
        $lastName = trim((string) ($payload['lastName'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $schoolId = trim((string) ($payload['schoolId'] ?? ''));
        $role = trim((string) ($payload['role'] ?? 'alumni'));
        $status = strtolower(trim((string) ($payload['accountStatus'] ?? 'active')));
        $password = (string) ($payload['password'] ?? '');
        $errors = [];

        if ($firstName === '') {
            $errors['firstName'] = 'Please enter a first name.';
        }

        if ($lastName === '') {
            $errors['lastName'] = 'Please enter a last name.';
        }

        if ($email === '') {
            $errors['email'] = 'Please enter an email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } elseif ($userRepo->findOneBy(['email' => $email]) !== null) {
            $errors['email'] = 'This email address is already associated with an account.';
        }

        if ($schoolId !== '' && $userRepo->findOneBy(['schoolId' => $schoolId]) !== null) {
            $errors['schoolId'] = 'This school ID is already registered.';
        }

        if (!in_array($role, ['admin', 'staff', 'alumni'], true)) {
            $errors['role'] = 'Role must be admin, staff, or alumni.';
        }

        if (!in_array($status, ['pending', 'active', 'inactive'], true)) {
            $errors['accountStatus'] = 'Status must be pending, active, or inactive.';
        }

        if ($password === '') {
            $errors['password'] = 'Please enter a password.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).+$/', $password)) {
            $errors['password'] = 'Password must contain uppercase, lowercase, number, and special character.';
        }

        if ($errors !== []) {
            return $this->json(['message' => 'User data is invalid.', 'errors' => $errors], 422);
        }

        $user = (new User())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setEmail($email)
            ->setSchoolId($schoolId !== '' ? $schoolId : null)
            ->setAccountStatus($status)
            ->setEmailVerifiedAt(new \DateTimeImmutable())
            ->setRoles(match ($role) {
                'admin' => ['ROLE_ADMIN'],
                'staff' => ['ROLE_STAFF'],
                default => [User::ROLE_ALUMNI],
            });

        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        $audit->log(
            'create_user',
            'User',
            $user->getId(),
            'Created user through admin API: ' . $user->getFullName() . ' (' . $user->getEmail() . ')'
        );

        return $this->json([
            'item' => $this->serializeUser($user),
            'message' => 'User created.',
        ], 201);
    }

    #[Route('/users/{id}', name: 'api_admin_user_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function user(User $user, GtsSurveyRepository $surveyRepo, GtsSurveyQuestionBank $questionBank): JsonResponse
    {
        return $this->json(['item' => $this->serializeUser($user, true, $surveyRepo, $questionBank)]);
    }

    #[Route('/users/{id}', name: 'api_admin_user_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateUser(
        User $user,
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        AuditLogger $audit,
    ): JsonResponse {
        $payload = $this->jsonPayload($request);
        $firstName = trim((string) ($payload['firstName'] ?? ''));
        $lastName = trim((string) ($payload['lastName'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $schoolId = trim((string) ($payload['schoolId'] ?? ''));
        $role = trim((string) ($payload['role'] ?? 'alumni'));
        $status = strtolower(trim((string) ($payload['accountStatus'] ?? 'active')));
        $password = (string) ($payload['password'] ?? '');
        $errors = [];

        if ($firstName === '') {
            $errors['firstName'] = 'Please enter a first name.';
        }

        if ($lastName === '') {
            $errors['lastName'] = 'Please enter a last name.';
        }

        if ($email === '') {
            $errors['email'] = 'Please enter an email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } else {
            $existingUser = $userRepo->findOneBy(['email' => $email]);
            if ($existingUser instanceof User && $existingUser->getId() !== $user->getId()) {
                $errors['email'] = 'This email address is already associated with an account.';
            }
        }

        if ($schoolId !== '') {
            $existingSchoolId = $userRepo->findOneBy(['schoolId' => $schoolId]);
            if ($existingSchoolId instanceof User && $existingSchoolId->getId() !== $user->getId()) {
                $errors['schoolId'] = 'This school ID is already registered.';
            }
        }

        if (!in_array($role, ['admin', 'staff', 'alumni'], true)) {
            $errors['role'] = 'Role must be admin, staff, or alumni.';
        }

        if (!in_array($status, ['pending', 'active', 'inactive'], true)) {
            $errors['accountStatus'] = 'Status must be pending, active, or inactive.';
        }

        if ($password !== '') {
            if (strlen($password) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).+$/', $password)) {
                $errors['password'] = 'Password must contain uppercase, lowercase, number, and special character.';
            }
        }

        $currentUser = $this->getUser();

        if ($currentUser instanceof User && $currentUser->getId() === $user->getId() && $role !== $this->primaryRole($user)) {
            $errors['role'] = 'You cannot change your own role.';
        }

        if ($errors !== []) {
            return $this->json(['message' => 'User data is invalid.', 'errors' => $errors], 422);
        }

        $user
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setEmail($email)
            ->setSchoolId($schoolId !== '' ? $schoolId : null)
            ->setAccountStatus($status)
            ->setRoles(match ($role) {
                'admin' => ['ROLE_ADMIN'],
                'staff' => ['ROLE_STAFF'],
                default => [User::ROLE_ALUMNI],
            });

        if ($password !== '') {
            $user->setPassword($passwordHasher->hashPassword($user, $password));
        }

        $em->flush();

        $audit->log(
            'edit_user',
            'User',
            $user->getId(),
            'Updated user through admin API: ' . $user->getFullName() . ' (' . $user->getEmail() . ')'
        );

        return $this->json([
            'item' => $this->serializeUser($user),
            'message' => 'User updated.',
        ]);
    }

    #[Route('/users/{id}', name: 'api_admin_user_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteUser(User $user, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifications): JsonResponse
    {
        $currentUser = $this->getUser();

        if (!$currentUser instanceof User) {
            return $this->json(['message' => 'Unable to verify current admin account.'], 403);
        }

        if ($currentUser->getId() === $user->getId()) {
            return $this->json(['message' => 'You cannot delete your own account.'], 409);
        }

        $id = $user->getId();
        $label = $user->getFullName() . ' (' . $user->getEmail() . ')';

        $em->createQuery('UPDATE App\\Entity\\AuditLog a SET a.performedBy = :replacement WHERE a.performedBy = :target')
            ->setParameter('replacement', $currentUser)
            ->setParameter('target', $user)
            ->execute();

        $em->remove($user);
        $em->flush();

        $audit->log(
            'delete_user',
            'User',
            $id,
            'Deleted user through admin API: ' . $label
        );
        $notifications->createAdminNotification(
            'account.deleted',
            'User deleted',
            'Deleted user: ' . $label,
            AdminNotification::SEVERITY_WARNING,
            '/users',
            'User',
            $id,
        );

        return $this->json(['message' => 'User deleted.']);
    }

    #[Route('/users/{id}/approve', name: 'api_admin_user_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approveUser(User $user, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifier): JsonResponse
    {
        $user->setAccountStatus('active');
        $em->flush();

        $audit->log(
            AuditLog::ACTION_APPROVE_USER,
            'User',
            $user->getId(),
            'Approved registration through admin API for ' . $user->getFullName() . ' (' . $user->getEmail() . ')'
        );

        try {
            $notifier->notifyAccountApproved($user);
        } catch (\Throwable) {
        }

        return $this->json(['item' => $this->serializeUser($user), 'message' => 'User approved.']);
    }

    #[Route('/users/{id}/status', name: 'api_admin_user_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateUserStatus(User $user, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = $this->jsonPayload($request);
        $status = strtolower(trim((string) ($payload['status'] ?? '')));

        if (!in_array($status, ['pending', 'active', 'inactive'], true)) {
            return $this->json(['error' => 'Status must be pending, active, or inactive.'], 422);
        }

        $user->setAccountStatus($status);
        $em->flush();

        return $this->json(['item' => $this->serializeUser($user), 'message' => 'User status updated.']);
    }

    #[Route('/users/{id}/toggle-status', name: 'api_admin_user_toggle_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleUserStatus(User $user, EntityManagerInterface $em): JsonResponse
    {
        $user->setAccountStatus($user->getAccountStatus() === 'active' ? 'inactive' : 'active');
        $em->flush();

        return $this->json(['item' => $this->serializeUser($user), 'message' => 'User status toggled.']);
    }

    #[Route('/verification', name: 'api_admin_verification', methods: ['GET'])]
    public function verification(Request $request, UserRepository $userRepo): JsonResponse
    {
        $search = trim((string) $request->query->get('q', ''));

        $buildStatusList = function (string $status, ?int $limit = null) use ($userRepo, $search): array {
            $qb = $userRepo->createQueryBuilder('u')
                ->leftJoin('u.alumni', 'a')
                ->addSelect('a')
                ->andWhere('u.accountStatus = :status')
                ->setParameter('status', $status)
                ->orderBy('u.dateRegistered', 'DESC');

            if ($search !== '') {
                $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR u.email LIKE :q')
                    ->setParameter('q', '%' . $search . '%');
            }

            if ($limit !== null) {
                $qb->setMaxResults($limit);
            }

            return array_map(fn (User $user): array => $this->serializeUser($user), $qb->getQuery()->getResult());
        };

        $pending = $buildStatusList('pending');

        return $this->json([
            'pending' => $pending,
            'approved' => $buildStatusList('active', 10),
            'denied' => $buildStatusList('inactive', 10),
            'counts' => [
                'pending' => count($pending),
                'approved' => $userRepo->count(['accountStatus' => 'active']),
                'denied' => $userRepo->count(['accountStatus' => 'inactive']),
            ],
        ]);
    }

    #[Route('/verification/{id}/approve', name: 'api_admin_verification_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approveVerification(User $user, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifier): JsonResponse
    {
        return $this->approveUser($user, $em, $audit, $notifier);
    }

    #[Route('/verification/{id}/deny', name: 'api_admin_verification_deny', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function denyVerification(User $user, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifier): JsonResponse
    {
        $user->setAccountStatus('inactive');
        $em->flush();

        $audit->log(
            AuditLog::ACTION_DENY_USER,
            'User',
            $user->getId(),
            'Denied registration through admin API for ' . $user->getFullName() . ' (' . $user->getEmail() . ')'
        );

        try {
            $notifier->notifyAccountDenied($user);
        } catch (\Throwable) {
        }

        return $this->json(['item' => $this->serializeUser($user), 'message' => 'User denied.']);
    }

    #[Route('/announcements', name: 'api_admin_announcements', methods: ['GET'])]
    public function announcements(Request $request, AnnouncementRepository $announcementRepo): JsonResponse
    {
        $limit = min($this->positiveInt($request->query->get('limit'), 25), 100);
        $active = $request->query->get('active');
        $criteria = $active === null ? [] : ['isActive' => filter_var($active, FILTER_VALIDATE_BOOL)];

        $announcements = $announcementRepo->findBy($criteria, ['datePosted' => 'DESC'], $limit);

        return $this->json([
            'items' => array_map(fn (Announcement $announcement): array => $this->serializeAnnouncement($announcement), $announcements),
            'meta' => [
                'limit' => $limit,
                'total' => $announcementRepo->count($criteria),
            ],
        ]);
    }

    #[Route('/announcements', name: 'api_admin_announcement_create', methods: ['POST'])]
    public function createAnnouncement(Request $request, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifications): JsonResponse
    {
        $announcement = new Announcement();
        $errors = $this->applyAnnouncementPayload($announcement, $request);

        if ($errors !== []) {
            return $this->json(['message' => 'Announcement data is invalid.', 'errors' => $errors], 422);
        }

        $user = $this->getUser();

        if ($user instanceof User) {
            $announcement->setPostedBy($user);
        }

        $em->persist($announcement);
        $em->flush();

        $audit->log(
            'create_announcement',
            'Announcement',
            $announcement->getId(),
            'Created announcement through admin API: ' . $announcement->getTitle()
        );
        $notifications->createAdminNotification(
            'content.announcement_created',
            'Announcement created',
            'Created announcement: ' . $announcement->getTitle(),
            AdminNotification::SEVERITY_INFO,
            '/announcements',
            'Announcement',
            $announcement->getId(),
        );

        return $this->json([
            'item' => $this->serializeAnnouncement($announcement),
            'message' => 'Announcement created.',
        ], 201);
    }

    #[Route('/announcements/{id}', name: 'api_admin_announcement_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateAnnouncement(Announcement $announcement, Request $request, EntityManagerInterface $em, AuditLogger $audit): JsonResponse
    {
        $errors = $this->applyAnnouncementPayload($announcement, $request);

        if ($errors !== []) {
            return $this->json(['message' => 'Announcement data is invalid.', 'errors' => $errors], 422);
        }

        $em->flush();

        $audit->log(
            'edit_announcement',
            'Announcement',
            $announcement->getId(),
            'Updated announcement through admin API: ' . $announcement->getTitle()
        );

        return $this->json([
            'item' => $this->serializeAnnouncement($announcement),
            'message' => 'Announcement updated.',
        ]);
    }

    #[Route('/announcements/{id}', name: 'api_admin_announcement_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteAnnouncement(Announcement $announcement, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifications): JsonResponse
    {
        $id = $announcement->getId();
        $title = $announcement->getTitle();

        $em->remove($announcement);
        $em->flush();

        $audit->log(
            'delete_announcement',
            'Announcement',
            $id,
            'Deleted announcement through admin API: ' . $title
        );
        $notifications->createAdminNotification(
            'content.announcement_deleted',
            'Announcement deleted',
            'Deleted announcement: ' . $title,
            AdminNotification::SEVERITY_WARNING,
            '/announcements',
            'Announcement',
            $id,
        );

        return $this->json(['message' => 'Announcement deleted.']);
    }

    #[Route('/jobs', name: 'api_admin_jobs', methods: ['GET'])]
    public function jobs(Request $request, JobPostingRepository $jobRepo): JsonResponse
    {
        $limit = min($this->positiveInt($request->query->get('limit'), 25), 100);
        $active = $request->query->get('active');
        $criteria = $active === null ? [] : ['isActive' => filter_var($active, FILTER_VALIDATE_BOOL)];

        $jobs = $jobRepo->findBy($criteria, ['datePosted' => 'DESC'], $limit);

        return $this->json([
            'items' => array_map(fn (JobPosting $job): array => $this->serializeJob($job), $jobs),
            'meta' => [
                'limit' => $limit,
                'total' => $jobRepo->count($criteria),
            ],
        ]);
    }

    #[Route('/jobs', name: 'api_admin_job_create', methods: ['POST'])]
    public function createJob(Request $request, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifications): JsonResponse
    {
        $job = new JobPosting();
        $errors = $this->applyJobPayload($job, $request);

        if ($errors !== []) {
            return $this->json(['message' => 'Job posting data is invalid.', 'errors' => $errors], 422);
        }

        $user = $this->getUser();

        if ($user instanceof User) {
            $job->setPostedBy($user);
        }

        $em->persist($job);
        $em->flush();

        $audit->log(
            'create_job',
            'JobPosting',
            $job->getId(),
            'Created job posting through admin API: ' . $job->getTitle()
        );
        $notifications->createAdminNotification(
            'content.job_created',
            'Job posting created',
            'Created job posting: ' . $job->getTitle(),
            AdminNotification::SEVERITY_INFO,
            '/jobs',
            'JobPosting',
            $job->getId(),
        );

        return $this->json([
            'item' => $this->serializeJob($job),
            'message' => 'Job posting created.',
        ], 201);
    }

    #[Route('/jobs/{id}', name: 'api_admin_job_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateJob(JobPosting $job, Request $request, EntityManagerInterface $em, AuditLogger $audit): JsonResponse
    {
        $errors = $this->applyJobPayload($job, $request);

        if ($errors !== []) {
            return $this->json(['message' => 'Job posting data is invalid.', 'errors' => $errors], 422);
        }

        $job->setDateUpdated(new \DateTime());
        $em->flush();

        $audit->log(
            'edit_job',
            'JobPosting',
            $job->getId(),
            'Updated job posting through admin API: ' . $job->getTitle()
        );

        return $this->json([
            'item' => $this->serializeJob($job),
            'message' => 'Job posting updated.',
        ]);
    }

    #[Route('/jobs/{id}', name: 'api_admin_job_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteJob(JobPosting $job, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifications): JsonResponse
    {
        $id = $job->getId();
        $title = $job->getTitle();

        $em->remove($job);
        $em->flush();

        $audit->log(
            'delete_job',
            'JobPosting',
            $id,
            'Deleted job posting through admin API: ' . $title
        );
        $notifications->createAdminNotification(
            'content.job_deleted',
            'Job posting deleted',
            'Deleted job posting: ' . $title,
            AdminNotification::SEVERITY_WARNING,
            '/jobs',
            'JobPosting',
            $id,
        );

        return $this->json(['message' => 'Job posting deleted.']);
    }

    #[Route('/academic', name: 'api_admin_academic', methods: ['GET'])]
    public function academic(CollegeRepository $collegeRepo, DepartmentRepository $departmentRepo): JsonResponse
    {
        $colleges = $collegeRepo->findBy([], ['name' => 'ASC']);
        $departments = $departmentRepo->findBy([], ['name' => 'ASC']);

        return $this->json([
            'colleges' => array_map(fn (College $college): array => $this->serializeCollege($college), $colleges),
            'departments' => array_map(fn (Department $department): array => $this->serializeDepartment($department), $departments),
        ]);
    }

    #[Route('/qr-registration', name: 'api_admin_qr_registration', methods: ['GET'])]
    public function qrRegistration(QrRegistrationBatchRepository $batchRepo): JsonResponse
    {
        $batches = $batchRepo->findAllOrdered();

        return $this->json([
            'items' => array_map(fn (QrRegistrationBatch $batch): array => $this->serializeQrBatch($batch), $batches),
            'meta' => [
                'total' => count($batches),
                'open' => count(array_filter($batches, static fn (QrRegistrationBatch $batch): bool => $batch->isOpen())),
                'defaultBatchYear' => (int) date('Y'),
                'maxBatchYear' => ((int) date('Y')) + 10,
            ],
        ]);
    }

    #[Route('/qr-registration', name: 'api_admin_qr_registration_create', methods: ['POST'])]
    public function createQrRegistrationBatch(
        Request $request,
        QrRegistrationBatchRepository $batchRepo,
        EntityManagerInterface $em,
        AuditLogger $audit,
        NotificationService $notifications,
    ): JsonResponse {
        $payload = $this->jsonPayload($request);
        $batchYear = is_numeric($payload['batchYear'] ?? null) ? (int) $payload['batchYear'] : 0;
        $maxYear = ((int) date('Y')) + 10;

        if ($batchYear < 1950 || $batchYear > $maxYear) {
            return $this->json([
                'message' => sprintf('Batch year must be between 1950 and %d.', $maxYear),
                'errors' => ['batchYear' => sprintf('Batch year must be between 1950 and %d.', $maxYear)],
            ], 422);
        }

        if ($batchRepo->findOneByBatchYear($batchYear) !== null) {
            return $this->json([
                'message' => sprintf('Batch %d already exists.', $batchYear),
                'errors' => ['batchYear' => sprintf('Batch %d already exists.', $batchYear)],
            ], 422);
        }

        $batch = (new QrRegistrationBatch())
            ->setBatchYear($batchYear)
            ->setIsOpen(filter_var($payload['isOpen'] ?? true, FILTER_VALIDATE_BOOL));

        $em->persist($batch);
        $em->flush();

        $audit->log(
            'create_qr_registration_batch',
            'QrRegistrationBatch',
            $batch->getId(),
            sprintf('Created QR registration batch %d through admin API.', $batchYear)
        );
        $notifications->createAdminNotification(
            'qr.batch_created',
            'QR batch created',
            sprintf('Batch %d QR registration was created.', $batchYear),
            AdminNotification::SEVERITY_INFO,
            '/qr-registration',
            'QrRegistrationBatch',
            $batch->getId(),
        );

        return $this->json([
            'item' => $this->serializeQrBatch($batch),
            'message' => sprintf('Batch %d QR registration created.', $batchYear),
        ], 201);
    }

    #[Route('/qr-registration/{id}/toggle', name: 'api_admin_qr_registration_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleQrRegistrationBatch(QrRegistrationBatch $batch, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifications): JsonResponse
    {
        $batch->setIsOpen(!$batch->isOpen());
        $em->flush();

        $audit->log(
            'toggle_qr_registration_batch',
            'QrRegistrationBatch',
            $batch->getId(),
            sprintf('%s QR registration batch %d through admin API.', $batch->isOpen() ? 'Opened' : 'Closed', $batch->getBatchYear())
        );
        $notifications->createAdminNotification(
            $batch->isOpen() ? 'qr.batch_opened' : 'qr.batch_closed',
            $batch->isOpen() ? 'QR batch opened' : 'QR batch closed',
            sprintf('Batch %d QR registration was %s.', $batch->getBatchYear(), $batch->isOpen() ? 'opened' : 'closed'),
            AdminNotification::SEVERITY_INFO,
            '/qr-registration',
            'QrRegistrationBatch',
            $batch->getId(),
        );

        return $this->json([
            'item' => $this->serializeQrBatch($batch),
            'message' => $batch->isOpen()
                ? sprintf('Batch %d QR registration reopened.', $batch->getBatchYear())
                : sprintf('Batch %d QR registration closed.', $batch->getBatchYear()),
        ]);
    }

    #[Route('/qr-registration/{id}', name: 'api_admin_qr_registration_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteQrRegistrationBatch(QrRegistrationBatch $batch, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifications): JsonResponse
    {
        $id = $batch->getId();
        $batchYear = $batch->getBatchYear();

        $em->remove($batch);
        $em->flush();

        $audit->log(
            'delete_qr_registration_batch',
            'QrRegistrationBatch',
            $id,
            sprintf('Deleted QR registration batch %d through admin API.', $batchYear)
        );
        $notifications->createAdminNotification(
            'qr.batch_deleted',
            'QR batch deleted',
            sprintf('Batch %d QR registration was deleted.', $batchYear),
            AdminNotification::SEVERITY_WARNING,
            '/qr-registration',
            'QrRegistrationBatch',
            $id,
        );

        return $this->json(['message' => sprintf('Batch %d QR registration deleted.', $batchYear)]);
    }

    #[Route('/gts/surveys', name: 'api_admin_gts_surveys', methods: ['GET'])]
    public function gtsSurveys(GtsSurveyTemplateRepository $templateRepo): JsonResponse
    {
        return $this->json([
            'items' => array_map(fn (GtsSurveyTemplate $template): array => $this->serializeSurveyTemplate($template), $templateRepo->findAllOrdered()),
        ]);
    }

    #[Route('/gts/surveys', name: 'api_admin_gts_survey_create', methods: ['POST'])]
    public function createGtsSurveyTemplate(Request $request, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifications): JsonResponse
    {
        $template = new GtsSurveyTemplate();
        $errors = $this->applySurveyTemplatePayload($template, $request);

        if ($errors !== []) {
            return $this->json(['message' => 'Survey template data is invalid.', 'errors' => $errors], 422);
        }

        $em->persist($template);
        $em->flush();

        $audit->log(
            'create_gts_survey_template',
            'GtsSurveyTemplate',
            $template->getId(),
            'Created GTS survey template through admin API: ' . $template->getTitle()
        );
        $notifications->createAdminNotification(
            'gts.template_created',
            'GTS survey created',
            'Created survey template: ' . $template->getTitle(),
            AdminNotification::SEVERITY_INFO,
            '/gts/surveys',
            'GtsSurveyTemplate',
            $template->getId(),
        );

        return $this->json([
            'item' => $this->serializeSurveyTemplate($template),
            'message' => 'Survey template created.',
        ], 201);
    }

    #[Route('/gts/surveys/{id}', name: 'api_admin_gts_survey_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateGtsSurveyTemplate(GtsSurveyTemplate $template, Request $request, EntityManagerInterface $em, AuditLogger $audit): JsonResponse
    {
        $errors = $this->applySurveyTemplatePayload($template, $request);

        if ($errors !== []) {
            return $this->json(['message' => 'Survey template data is invalid.', 'errors' => $errors], 422);
        }

        $em->flush();

        $audit->log(
            'edit_gts_survey_template',
            'GtsSurveyTemplate',
            $template->getId(),
            'Updated GTS survey template through admin API: ' . $template->getTitle()
        );

        return $this->json([
            'item' => $this->serializeSurveyTemplate($template),
            'message' => 'Survey template updated.',
        ]);
    }

    #[Route('/gts/surveys/{id}', name: 'api_admin_gts_survey_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteGtsSurveyTemplate(GtsSurveyTemplate $template, EntityManagerInterface $em, AuditLogger $audit, NotificationService $notifications): JsonResponse
    {
        if ($template->getCampaigns()->count() > 0) {
            return $this->json(['message' => 'This survey template is linked to campaigns and cannot be deleted.'], 409);
        }

        $id = $template->getId();
        $title = $template->getTitle();

        $em->remove($template);
        $em->flush();

        $audit->log(
            'delete_gts_survey_template',
            'GtsSurveyTemplate',
            $id,
            'Deleted GTS survey template through admin API: ' . $title
        );
        $notifications->createAdminNotification(
            'gts.template_deleted',
            'GTS survey deleted',
            'Deleted survey template: ' . $title,
            AdminNotification::SEVERITY_WARNING,
            '/gts/surveys',
            'GtsSurveyTemplate',
            $id,
        );

        return $this->json(['message' => 'Survey template deleted.']);
    }

    #[Route('/gts/surveys/{id}/questions', name: 'api_admin_gts_survey_questions', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function gtsSurveyQuestions(GtsSurveyTemplate $template, GtsSurveyQuestionRepository $questionRepo): JsonResponse
    {
        return $this->json([
            'survey' => $this->serializeSurveyTemplate($template),
            'items' => array_map(fn (GtsSurveyQuestion $question): array => $this->serializeSurveyQuestion($question), $questionRepo->findOrderedByTemplate($template)),
        ]);
    }

    #[Route('/gts/surveys/{id}/questions', name: 'api_admin_gts_survey_question_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createGtsSurveyQuestion(
        GtsSurveyTemplate $template,
        Request $request,
        EntityManagerInterface $em,
        GtsSurveyQuestionBank $questionBank,
        AuditLogger $audit,
    ): JsonResponse {
        $question = (new GtsSurveyQuestion())->setSurveyTemplate($template);
        $errors = $this->applySurveyQuestionPayload($question, $request, $questionBank);

        if ($errors !== []) {
            return $this->json(['message' => 'Survey question data is invalid.', 'errors' => $errors], 422);
        }

        $em->persist($question);
        $em->flush();

        $audit->log(
            'create_gts_survey_question',
            'GtsSurveyQuestion',
            $question->getId(),
            'Created GTS survey question through admin API for: ' . $template->getTitle()
        );

        return $this->json([
            'item' => $this->serializeSurveyQuestion($question),
            'message' => 'Survey question created.',
        ], 201);
    }

    #[Route('/gts/questions/{id}', name: 'api_admin_gts_survey_question_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateGtsSurveyQuestion(
        GtsSurveyQuestion $question,
        Request $request,
        EntityManagerInterface $em,
        GtsSurveyQuestionBank $questionBank,
        AuditLogger $audit,
    ): JsonResponse {
        $errors = $this->applySurveyQuestionPayload($question, $request, $questionBank);

        if ($errors !== []) {
            return $this->json(['message' => 'Survey question data is invalid.', 'errors' => $errors], 422);
        }

        $em->flush();

        $audit->log(
            'edit_gts_survey_question',
            'GtsSurveyQuestion',
            $question->getId(),
            'Updated GTS survey question through admin API.'
        );

        return $this->json([
            'item' => $this->serializeSurveyQuestion($question),
            'message' => 'Survey question updated.',
        ]);
    }

    #[Route('/gts/questions/{id}', name: 'api_admin_gts_survey_question_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteGtsSurveyQuestion(GtsSurveyQuestion $question, EntityManagerInterface $em, AuditLogger $audit): JsonResponse
    {
        $id = $question->getId();

        $em->remove($question);
        $em->flush();

        $audit->log(
            'delete_gts_survey_question',
            'GtsSurveyQuestion',
            $id,
            'Deleted GTS survey question through admin API.'
        );

        return $this->json(['message' => 'Survey question deleted.']);
    }

    #[Route('/gts/surveys/{id}/questions/import-defaults', name: 'api_admin_gts_survey_question_import_defaults', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function importGtsSurveyDefaultQuestions(
        GtsSurveyTemplate $template,
        GtsSurveyQuestionRepository $questionRepo,
        GtsSurveyQuestionBank $questionBank,
        EntityManagerInterface $em,
        AuditLogger $audit,
        NotificationService $notifications,
    ): JsonResponse {
        if (count($questionRepo->findOrderedByTemplate($template)) > 0) {
            return $this->json(['message' => 'Default questionnaire import is only available when this survey has no questions yet.'], 409);
        }

        $count = $questionBank->importDefaults($em, $template);
        $em->flush();

        $audit->log(
            'import_gts_default_questions',
            'GtsSurveyTemplate',
            $template->getId(),
            sprintf('Imported %d default GTS survey questions through admin API.', $count)
        );
        $notifications->createAdminNotification(
            'gts.questions_imported',
            'GTS questions imported',
            sprintf('Imported %d default questions into %s.', $count, $template->getTitle()),
            AdminNotification::SEVERITY_SUCCESS,
            '/gts/surveys',
            'GtsSurveyTemplate',
            $template->getId(),
        );

        return $this->json([
            'itemsImported' => $count,
            'message' => sprintf('Imported %d default survey questions.', $count),
        ]);
    }

    #[Route('/gts/campaigns', name: 'api_admin_gts_campaigns', methods: ['GET'])]
    public function gtsCampaigns(SurveyCampaignRepository $campaignRepo, SurveyInvitationRepository $invitationRepo): JsonResponse
    {
        $campaigns = $campaignRepo->findAllOrdered();

        return $this->json([
            'items' => array_map(fn (SurveyCampaign $campaign): array => $this->serializeCampaign($campaign, $invitationRepo), $campaigns),
        ]);
    }

    #[Route('/gts/responses', name: 'api_admin_gts_responses', methods: ['GET'])]
    public function gtsResponses(
        Request $request,
        GtsSurveyRepository $surveyRepo,
        GtsSurveyQuestionBank $questionBank,
    ): JsonResponse
    {
        $page = $this->positiveInt($request->query->get('page'), 1);
        $limit = min($this->positiveInt($request->query->get('limit'), 25), 100);
        $search = trim((string) $request->query->get('q', ''));
        $surveyId = $request->query->has('surveyId') ? $this->positiveInt($request->query->get('surveyId'), 0) : null;
        $campaignId = $request->query->has('campaignId') ? $this->positiveInt($request->query->get('campaignId'), 0) : null;
        $batchYear = $request->query->has('batchYear') ? $this->campaignTargetBatchYear($request->query->get('batchYear')) : null;

        $qb = $surveyRepo->createQueryBuilder('s')
            ->leftJoin('s.user', 'u')
            ->addSelect('u')
            ->leftJoin('s.surveyTemplate', 't')
            ->addSelect('t')
            ->leftJoin('s.surveyInvitation', 'i')
            ->addSelect('i')
            ->leftJoin('i.campaign', 'c')
            ->addSelect('c')
            ->orderBy('s.createdAt', 'DESC');

        if ($search !== '') {
            $qb->andWhere('s.name LIKE :responseSearch OR s.emailAddress LIKE :responseSearch OR u.firstName LIKE :responseSearch OR u.lastName LIKE :responseSearch OR u.email LIKE :responseSearch')
                ->setParameter('responseSearch', '%' . $search . '%');
        }

        if ($surveyId !== null && $surveyId > 0) {
            $qb->andWhere('t.id = :surveyId')
                ->setParameter('surveyId', $surveyId);
        }

        if ($campaignId !== null && $campaignId > 0) {
            $qb->andWhere('c.id = :campaignId')
                ->setParameter('campaignId', $campaignId);
        }

        if ($batchYear !== null) {
            $qb->andWhere('c.targetGraduationYears LIKE :batchYearNeedle')
                ->setParameter('batchYearNeedle', '%"' . $batchYear . '"%');
        }

        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        $paginator = new Paginator($qb);
        $total = count($paginator);

        return $this->json([
            'items' => array_map(
                fn (GtsSurvey $survey): array => $this->serializeGtsResponseRow($survey, $questionBank, $surveyRepo),
                iterator_to_array($paginator),
            ),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    #[Route('/gts/responses/{id}', name: 'api_admin_gts_response_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function gtsResponse(
        GtsSurvey $survey,
        GtsSurveyQuestionBank $questionBank,
        GtsSurveyRepository $surveyRepo,
    ): JsonResponse
    {
        return $this->json([
            'item' => $this->serializeGtsResponseDetail($survey, $questionBank, $surveyRepo),
        ]);
    }

    #[Route('/gts/campaigns/preview', name: 'api_admin_gts_campaign_preview', methods: ['POST'])]
    public function previewGtsCampaignRecipients(Request $request, AlumniRepository $alumniRepo): JsonResponse
    {
        $payload = $this->jsonPayload($request);
        $targetBatchYear = $this->campaignTargetBatchYear($payload['targetBatchYear'] ?? null);
        $targetCollege = $this->nullableTrimmedString($payload['targetCollege'] ?? null);
        $targetCourse = $this->nullableTrimmedString($payload['targetCourse'] ?? null);

        if ($targetBatchYear === null) {
            return $this->json([
                'count' => 0,
                'items' => [],
                'message' => 'Choose a target batch year first.',
            ]);
        }

        $recipientsQb = $alumniRepo->searchEligibleSurveyRecipients($targetBatchYear, $targetCollege, $targetCourse);
        $recipientCount = (int) (clone $recipientsQb)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $recipients = (clone $recipientsQb)
            ->setMaxResults(25)
            ->getQuery()
            ->getResult();

        return $this->json([
            'count' => $recipientCount,
            'items' => array_map(fn (Alumni $alumni): array => $this->serializeCampaignRecipient($alumni), $recipients),
        ]);
    }

    #[Route('/gts/campaigns', name: 'api_admin_gts_campaign_create', methods: ['POST'])]
    public function createGtsCampaign(
        Request $request,
        AlumniRepository $alumniRepo,
        GtsSurveyTemplateRepository $templateRepo,
        SurveyInvitationRepository $invitationRepo,
        EntityManagerInterface $em,
        SurveyCampaignDispatchService $campaignDispatchService,
        AuditLogger $audit,
        NotificationService $notifications,
    ): JsonResponse {
        $payload = $this->jsonPayload($request);
        $surveyTemplateId = is_numeric($payload['surveyTemplateId'] ?? null) ? (int) $payload['surveyTemplateId'] : 0;
        $template = $surveyTemplateId > 0 ? $templateRepo->find($surveyTemplateId) : null;
        $targetBatchYear = $this->campaignTargetBatchYear($payload['targetBatchYear'] ?? null);
        $targetCollege = $this->nullableTrimmedString($payload['targetCollege'] ?? null);
        $targetCourse = $this->nullableTrimmedString($payload['targetCourse'] ?? null);
        $name = trim((string) ($payload['name'] ?? ''));
        $emailSubject = trim((string) ($payload['emailSubject'] ?? ''));
        $emailBody = trim((string) ($payload['emailBody'] ?? ''));
        $expiryDays = is_numeric($payload['expiryDays'] ?? null) ? (int) $payload['expiryDays'] : 30;
        $sendMode = strtolower(trim((string) ($payload['sendMode'] ?? 'now')));
        $scheduledSendAt = null;
        $errors = [];

        if (!$template instanceof GtsSurveyTemplate) {
            $errors['surveyTemplateId'] = 'Please choose a survey.';
        } elseif (!$template->isActive()) {
            $errors['surveyTemplateId'] = 'Please choose an active survey.';
        } elseif ($template->getQuestions()->count() === 0) {
            $errors['surveyTemplateId'] = 'Please add questions before sending this survey.';
        }

        if ($targetBatchYear === null) {
            $errors['targetBatchYear'] = 'Please choose a target batch.';
        }

        if ($name === '') {
            $errors['name'] = 'Please enter a campaign name.';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'Campaign name must be 255 characters or fewer.';
        }

        if ($emailSubject === '') {
            $errors['emailSubject'] = 'Please enter an email subject.';
        } elseif (mb_strlen($emailSubject) > 255) {
            $errors['emailSubject'] = 'Email subject must be 255 characters or fewer.';
        }

        if ($emailBody === '') {
            $errors['emailBody'] = 'Please enter an email body.';
        }

        if ($expiryDays < 1 || $expiryDays > 180) {
            $errors['expiryDays'] = 'Expiry days must be between 1 and 180.';
        }

        if (!in_array($sendMode, ['now', 'schedule'], true)) {
            $errors['sendMode'] = 'Send mode must be send now or schedule.';
        }

        if ($sendMode === 'schedule') {
            $scheduledSendAt = $this->parseScheduledSendAt($payload['scheduledSendAt'] ?? null);

            if (!$scheduledSendAt instanceof \DateTimeImmutable) {
                $errors['scheduledSendAt'] = 'Please choose when the campaign should be sent.';
            } elseif ($scheduledSendAt <= new \DateTimeImmutable()) {
                $errors['scheduledSendAt'] = 'Please choose a future send date.';
            }
        }

        if ($errors !== []) {
            return $this->json(['message' => 'Campaign data is invalid.', 'errors' => $errors], 422);
        }

        $recipientsQb = $alumniRepo->searchEligibleSurveyRecipients($targetBatchYear, $targetCollege, $targetCourse);
        $recipientCount = (int) (clone $recipientsQb)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($recipientCount === 0) {
            return $this->json([
                'message' => 'No active alumni recipients found for the selected batch and filters.',
                'errors' => ['targetBatchYear' => 'No recipients found for this target batch.'],
            ], 422);
        }

        $currentUser = $this->getUser();
        $campaign = (new SurveyCampaign())
            ->setSurveyTemplate($template)
            ->setName($name)
            ->setEmailSubject($emailSubject)
            ->setEmailBody($emailBody)
            ->setTargetBatchYear($targetBatchYear)
            ->setTargetCollege($targetCollege)
            ->setTargetCourse($targetCourse)
            ->setExpiryDays($expiryDays)
            ->setCreatedBy($currentUser instanceof User ? $currentUser->getEmail() : null);

        if ($sendMode === 'schedule') {
            $campaign
                ->setStatus('scheduled')
                ->setScheduledSendAt($scheduledSendAt);

            $em->persist($campaign);
            $em->flush();

            $audit->log(
                'schedule_gts_campaign',
                'SurveyCampaign',
                $campaign->getId(),
                sprintf('Scheduled GTS campaign through admin API: %s (%d eligible recipient(s)).', $campaign->getName(), $recipientCount)
            );
            $notifications->createAdminNotification(
                'gts.campaign_scheduled',
                'GTS campaign scheduled',
                sprintf('%s was scheduled for %s.', $campaign->getName(), $scheduledSendAt?->format('M d, Y h:i A')),
                AdminNotification::SEVERITY_INFO,
                '/gts/campaigns',
                'SurveyCampaign',
                $campaign->getId(),
            );

            return $this->json([
                'item' => $this->serializeCampaign($campaign, $invitationRepo),
                'message' => sprintf('Campaign scheduled for %s with %d eligible recipient(s).', $scheduledSendAt?->format('M d, Y h:i A'), $recipientCount),
            ], 201);
        }

        $queuedCount = $campaignDispatchService->dispatchCampaign($campaign, $this->resolveCampaignDispatchBaseUrl($request));

        $audit->log(
            'create_gts_campaign',
            'SurveyCampaign',
            $campaign->getId(),
            sprintf('Queued GTS campaign through admin API: %s (%d recipient(s)).', $campaign->getName(), $queuedCount)
        );
        $notifications->createAdminNotification(
            'gts.campaign_queued',
            'GTS campaign queued',
            sprintf('%s was queued with %d invitation email(s).', $campaign->getName(), $queuedCount),
            AdminNotification::SEVERITY_SUCCESS,
            '/gts/campaigns',
            'SurveyCampaign',
            $campaign->getId(),
        );

        return $this->json([
            'item' => $this->serializeCampaign($campaign, $invitationRepo),
            'message' => sprintf('Campaign queued with %d invitation email(s).', $queuedCount),
        ], 201);
    }

    #[Route('/gts/campaigns/{id}', name: 'api_admin_gts_campaign_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateGtsCampaign(
        SurveyCampaign $campaign,
        Request $request,
        AlumniRepository $alumniRepo,
        GtsSurveyTemplateRepository $templateRepo,
        SurveyInvitationRepository $invitationRepo,
        EntityManagerInterface $em,
        AuditLogger $audit,
        NotificationService $notifications,
    ): JsonResponse {
        if (!in_array($campaign->getStatus(), ['draft', 'scheduled'], true)) {
            return $this->json([
                'message' => 'Only scheduled or draft campaigns can be edited.',
            ], 409);
        }

        $payload = $this->jsonPayload($request);
        $surveyTemplateId = is_numeric($payload['surveyTemplateId'] ?? null)
            ? (int) $payload['surveyTemplateId']
            : $campaign->getSurveyTemplate()->getId();
        $template = $templateRepo->find($surveyTemplateId);
        $targetBatchYear = $this->campaignTargetBatchYear($payload['targetBatchYear'] ?? null);
        $name = trim((string) ($payload['name'] ?? ''));
        $emailSubject = trim((string) ($payload['emailSubject'] ?? ''));
        $emailBody = trim((string) ($payload['emailBody'] ?? ''));
        $expiryDays = is_numeric($payload['expiryDays'] ?? null) ? (int) $payload['expiryDays'] : 30;
        $scheduledSendAt = $this->parseScheduledSendAt($payload['scheduledSendAt'] ?? null);
        $errors = [];

        if (!$template instanceof GtsSurveyTemplate) {
            $errors['surveyTemplateId'] = 'Please choose a survey.';
        } elseif (!$template->isActive()) {
            $errors['surveyTemplateId'] = 'Please choose an active survey.';
        } elseif ($template->getQuestions()->count() === 0) {
            $errors['surveyTemplateId'] = 'Please add questions before scheduling this survey.';
        }

        if ($targetBatchYear === null) {
            $errors['targetBatchYear'] = 'Please choose a target batch.';
        }

        if ($name === '') {
            $errors['name'] = 'Please enter a campaign name.';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'Campaign name must be 255 characters or fewer.';
        }

        if ($emailSubject === '') {
            $errors['emailSubject'] = 'Please enter an email subject.';
        } elseif (mb_strlen($emailSubject) > 255) {
            $errors['emailSubject'] = 'Email subject must be 255 characters or fewer.';
        }

        if ($emailBody === '') {
            $errors['emailBody'] = 'Please enter an email body.';
        }

        if ($expiryDays < 1 || $expiryDays > 180) {
            $errors['expiryDays'] = 'Expiry days must be between 1 and 180.';
        }

        if (!$scheduledSendAt instanceof \DateTimeImmutable) {
            $errors['scheduledSendAt'] = 'Please choose when the campaign should be sent.';
        } elseif ($scheduledSendAt <= new \DateTimeImmutable()) {
            $errors['scheduledSendAt'] = 'Please choose a future send date.';
        }

        if ($errors !== []) {
            return $this->json(['message' => 'Campaign data is invalid.', 'errors' => $errors], 422);
        }

        $recipientsQb = $alumniRepo->searchEligibleSurveyRecipients($targetBatchYear, $campaign->getTargetCollege(), $campaign->getTargetCourse());
        $recipientCount = (int) (clone $recipientsQb)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($recipientCount === 0) {
            return $this->json([
                'message' => 'No active alumni recipients found for the selected batch.',
                'errors' => ['targetBatchYear' => 'No recipients found for this target batch.'],
            ], 422);
        }

        $campaign
            ->setSurveyTemplate($template)
            ->setName($name)
            ->setEmailSubject($emailSubject)
            ->setEmailBody($emailBody)
            ->setTargetBatchYear($targetBatchYear)
            ->setExpiryDays($expiryDays)
            ->setScheduledSendAt($scheduledSendAt)
            ->setStatus('scheduled');

        $em->persist($campaign);
        $em->flush();

        $audit->log(
            'edit_gts_campaign',
            'SurveyCampaign',
            $campaign->getId(),
            sprintf('Updated scheduled GTS campaign through admin API: %s.', $campaign->getName())
        );

        return $this->json([
            'item' => $this->serializeCampaign($campaign, $invitationRepo),
            'message' => 'Campaign updated.',
        ]);
    }

    #[Route('/gts/campaigns/{id}/close', name: 'api_admin_gts_campaign_close', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function closeGtsCampaign(
        SurveyCampaign $campaign,
        SurveyInvitationRepository $invitationRepo,
        EntityManagerInterface $em,
        AuditLogger $audit,
    ): JsonResponse {
        if ($campaign->getStatus() === 'cancelled') {
            return $this->json([
                'item' => $this->serializeCampaign($campaign, $invitationRepo),
                'message' => 'Campaign is already closed.',
            ]);
        }

        $now = new \DateTimeImmutable();
        foreach ($invitationRepo->findByCampaign($campaign) as $invitation) {
            if (in_array($invitation->getStatus(), [SurveyInvitation::STATUS_QUEUED, SurveyInvitation::STATUS_SENT, SurveyInvitation::STATUS_OPENED], true)) {
                $invitation
                    ->setStatus(SurveyInvitation::STATUS_EXPIRED)
                    ->setExpiresAt($now);
                $em->persist($invitation);
            }
        }

        $campaign->setStatus('cancelled');
        $em->persist($campaign);
        $em->flush();

        $audit->log(
            'close_gts_campaign',
            'SurveyCampaign',
            $campaign->getId(),
            'Closed GTS campaign through admin API.'
        );
        $notifications->createAdminNotification(
            'gts.campaign_closed',
            'GTS campaign closed',
            sprintf('%s was closed and remaining invitations were expired.', $campaign->getName()),
            AdminNotification::SEVERITY_WARNING,
            '/gts/campaigns',
            'SurveyCampaign',
            $campaign->getId(),
        );

        return $this->json([
            'item' => $this->serializeCampaign($campaign, $invitationRepo),
            'message' => 'Campaign closed. Remaining open invitations were expired.',
        ]);
    }

    #[Route('/gts/campaigns/{id}', name: 'api_admin_gts_campaign_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteGtsCampaign(
        SurveyCampaign $campaign,
        SurveyInvitationRepository $invitationRepo,
        EntityManagerInterface $em,
        AuditLogger $audit,
        NotificationService $notifications,
    ): JsonResponse {
        if ($invitationRepo->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_COMPLETED) > 0) {
            return $this->json([
                'message' => 'This campaign already has submitted responses and cannot be deleted. Close it instead.',
            ], 409);
        }

        $campaignName = $campaign->getName();
        $campaignId = $campaign->getId();

        $em->remove($campaign);
        $em->flush();

        $audit->log(
            'delete_gts_campaign',
            'SurveyCampaign',
            $campaignId,
            'Deleted GTS campaign through admin API: ' . $campaignName
        );
        $notifications->createAdminNotification(
            'gts.campaign_deleted',
            'GTS campaign deleted',
            'Deleted campaign: ' . $campaignName,
            AdminNotification::SEVERITY_WARNING,
            '/gts/campaigns',
            'SurveyCampaign',
            $campaignId,
        );

        return $this->json(['message' => 'Campaign deleted.']);
    }

    #[Route('/audit-logs', name: 'api_admin_audit_logs', methods: ['GET'])]
    public function auditLogs(Request $request, AuditLogRepository $auditLogRepo): JsonResponse
    {
        $limit = min($this->positiveInt($request->query->get('limit'), 50), 100);
        $action = trim((string) $request->query->get('action', '')) ?: null;
        $userId = $request->query->has('user') ? $this->positiveInt($request->query->get('user'), 0) : null;

        return $this->json([
            'items' => array_map(fn (AuditLog $log): array => $this->serializeAuditLog($log), $auditLogRepo->findRecent($limit, $action, $userId)),
            'meta' => ['limit' => $limit],
        ]);
    }

    private function resolveCampaignDispatchBaseUrl(Request $request): string
    {
        $frontendUrl = trim((string) $request->headers->get('X-Frontend-Url', ''));
        if ($frontendUrl !== '' && filter_var($frontendUrl, FILTER_VALIDATE_URL)) {
            return rtrim($frontendUrl, '/');
        }

        return $request->getSchemeAndHttpHost();
    }

    private function serializeUser(
        User $user,
        bool $includeAlumni = false,
        ?GtsSurveyRepository $surveyRepo = null,
        ?GtsSurveyQuestionBank $questionBank = null,
    ): array
    {
        $alumni = $user->getAlumni();

        $data = [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'email' => $user->getEmail(),
            'schoolId' => $user->getSchoolId(),
            'roles' => $user->getRoles(),
            'primaryRole' => $this->primaryRole($user),
            'accountStatus' => $user->getAccountStatus(),
            'dateRegistered' => $this->formatDate($user->getDateRegistered()),
            'lastLogin' => $this->formatDate($user->getLastLogin()),
            'lastActivity' => $this->formatDate($user->getLastActivity()),
            'emailVerifiedAt' => $this->formatDate($user->getEmailVerifiedAt()),
            'hasAlumniRecord' => $alumni instanceof Alumni,
        ];

        if ($includeAlumni || $alumni instanceof Alumni) {
            $data['alumni'] = $alumni instanceof Alumni ? $this->serializeAlumni($alumni, $user, $surveyRepo, $questionBank) : null;
        }

        return $data;
    }

    private function serializeAlumni(
        Alumni $alumni,
        ?User $user = null,
        ?GtsSurveyRepository $surveyRepo = null,
        ?GtsSurveyQuestionBank $questionBank = null,
    ): array
    {
        $latestSurvey = $user instanceof User && $surveyRepo instanceof GtsSurveyRepository
            ? $surveyRepo->findLatestByUser($user)
            : null;
        $latestSurveyEmployment = $latestSurvey instanceof GtsSurvey && $questionBank instanceof GtsSurveyQuestionBank
            ? $questionBank->extractEmploymentSummary($latestSurvey)
            : null;

        return [
            'id' => $alumni->getId(),
            'studentNumber' => $alumni->getStudentNumber(),
            'fullName' => $alumni->getFullName(),
            'emailAddress' => $alumni->getEmailAddress(),
            'college' => $alumni->getCollege(),
            'course' => $alumni->getCourse(),
            'degreeProgram' => $alumni->getDegreeProgram(),
            'yearGraduated' => $alumni->getYearGraduated(),
            'employmentStatus' => $alumni->getEmploymentStatus(),
            'jobTitle' => $alumni->getJobTitle(),
            'companyName' => $alumni->getCompanyName(),
            'tracerStatus' => $alumni->getTracerStatus(),
            'lastTracerSubmissionAt' => $this->formatDate($alumni->getLastTracerSubmissionAt()),
            'latestSurvey' => $latestSurvey instanceof GtsSurvey ? [
                'id' => $latestSurvey->getId(),
                'submittedAt' => $this->formatDate($latestSurvey->getCreatedAt()),
                'employmentStatus' => $latestSurveyEmployment['employmentStatus'] ?? null,
                'presentlyEmployed' => $latestSurveyEmployment['presentlyEmployed'] ?? null,
                'occupation' => $latestSurveyEmployment['occupation'] ?? null,
                'companyName' => $latestSurveyEmployment['companyName'] ?? null,
                'companyAddress' => $latestSurveyEmployment['companyAddress'] ?? null,
                'surveyTemplate' => $latestSurvey->getSurveyTemplate() ? [
                    'id' => $latestSurvey->getSurveyTemplate()->getId(),
                    'title' => $latestSurvey->getSurveyTemplate()->getTitle(),
                ] : null,
                'campaign' => $latestSurvey->getSurveyInvitation()?->getCampaign() ? [
                    'id' => $latestSurvey->getSurveyInvitation()?->getCampaign()?->getId(),
                    'name' => $latestSurvey->getSurveyInvitation()?->getCampaign()?->getName(),
                ] : null,
            ] : null,
        ];
    }

    private function serializeAnnouncement(Announcement $announcement): array
    {
        return [
            'id' => $announcement->getId(),
            'title' => $announcement->getTitle(),
            'description' => $announcement->getDescription(),
            'category' => $announcement->getCategory(),
            'eventStartAt' => $this->formatDate($announcement->getEventStartAt()),
            'location' => $announcement->getLocation(),
            'joinUrl' => $announcement->getJoinUrl(),
            'isActive' => $announcement->isActive(),
            'datePosted' => $this->formatDate($announcement->getDatePosted()),
            'postedBy' => $announcement->getPostedBy() ? [
                'id' => $announcement->getPostedBy()->getId(),
                'fullName' => $announcement->getPostedBy()->getFullName(),
            ] : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function applyAnnouncementPayload(Announcement $announcement, Request $request): array
    {
        $payload = $this->jsonPayload($request);
        $title = trim((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $category = trim((string) ($payload['category'] ?? ''));
        $eventStartAt = trim((string) ($payload['eventStartAt'] ?? ''));
        $location = trim((string) ($payload['location'] ?? ''));
        $joinUrl = trim((string) ($payload['joinUrl'] ?? ''));
        $isActive = $payload['isActive'] ?? true;
        $errors = [];
        $eventStartAtValue = null;

        if ($title === '') {
            $errors['title'] = 'Please enter a title.';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Title must be 255 characters or fewer.';
        }

        if ($description === '') {
            $errors['description'] = 'Please enter a description.';
        }

        if (mb_strlen($category) > 100) {
            $errors['category'] = 'Category must be 100 characters or fewer.';
        }

        if ($eventStartAt !== '') {
            try {
                $eventStartAtValue = new \DateTimeImmutable($eventStartAt);
            } catch (\Exception) {
                $errors['eventStartAt'] = 'Please enter a valid date and time.';
            }
        }

        if (mb_strlen($location) > 255) {
            $errors['location'] = 'Location must be 255 characters or fewer.';
        }

        if (mb_strlen($joinUrl) > 2048) {
            $errors['joinUrl'] = 'Join link must be 2048 characters or fewer.';
        } elseif ($joinUrl !== '' && filter_var($joinUrl, FILTER_VALIDATE_URL) === false) {
            $errors['joinUrl'] = 'Please enter a valid URL, including https://.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $announcement
            ->setTitle($title)
            ->setDescription($description)
            ->setCategory($category !== '' ? $category : null)
            ->setEventStartAt($eventStartAtValue)
            ->setLocation($location !== '' ? $location : null)
            ->setJoinUrl($joinUrl !== '' ? $joinUrl : null)
            ->setIsActive(filter_var($isActive, FILTER_VALIDATE_BOOL));

        return [];
    }

    private function serializeJob(JobPosting $job): array
    {
        return [
            'id' => $job->getId(),
            'title' => $job->getTitle(),
            'companyName' => $job->getCompanyName(),
            'location' => $job->getLocation(),
            'description' => $job->getDescription(),
            'requirements' => $job->getRequirements(),
            'salaryRange' => $job->getSalaryRange(),
            'employmentType' => $job->getEmploymentType(),
            'industry' => $job->getIndustry(),
            'relatedCourse' => $job->getRelatedCourse(),
            'contactEmail' => $job->getContactEmail(),
            'applicationLink' => $job->getApplicationLink(),
            'deadline' => $this->formatDate($job->getDeadline(), 'Y-m-d'),
            'imageFilename' => $job->getImageFilename(),
            'isActive' => $job->isActive(),
            'isExpired' => $job->isExpired(),
            'datePosted' => $this->formatDate($job->getDatePosted()),
            'dateUpdated' => $this->formatDate($job->getDateUpdated()),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function applyJobPayload(JobPosting $job, Request $request): array
    {
        $payload = $this->jsonPayload($request);
        $title = trim((string) ($payload['title'] ?? ''));
        $companyName = trim((string) ($payload['companyName'] ?? ''));
        $location = trim((string) ($payload['location'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $requirements = trim((string) ($payload['requirements'] ?? ''));
        $salaryRange = trim((string) ($payload['salaryRange'] ?? ''));
        $employmentType = trim((string) ($payload['employmentType'] ?? ''));
        $industry = trim((string) ($payload['industry'] ?? ''));
        $relatedCourse = trim((string) ($payload['relatedCourse'] ?? ''));
        $contactEmail = trim((string) ($payload['contactEmail'] ?? ''));
        $applicationLink = trim((string) ($payload['applicationLink'] ?? ''));
        $deadline = trim((string) ($payload['deadline'] ?? ''));
        $isActive = $payload['isActive'] ?? true;
        $errors = [];

        if ($title === '') {
            $errors['title'] = 'Please enter a job title.';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Job title must be 255 characters or fewer.';
        }

        if ($companyName === '') {
            $errors['companyName'] = 'Please enter a company name.';
        } elseif (mb_strlen($companyName) > 255) {
            $errors['companyName'] = 'Company name must be 255 characters or fewer.';
        }

        if ($description === '') {
            $errors['description'] = 'Please enter a job description.';
        }

        if ($location !== '' && mb_strlen($location) > 500) {
            $errors['location'] = 'Location must be 500 characters or fewer.';
        }

        if ($salaryRange !== '' && mb_strlen($salaryRange) > 100) {
            $errors['salaryRange'] = 'Salary range must be 100 characters or fewer.';
        }

        if ($employmentType !== '' && mb_strlen($employmentType) > 100) {
            $errors['employmentType'] = 'Employment type must be 100 characters or fewer.';
        }

        if ($industry !== '' && mb_strlen($industry) > 255) {
            $errors['industry'] = 'Industry must be 255 characters or fewer.';
        }

        if ($relatedCourse !== '' && mb_strlen($relatedCourse) > 255) {
            $errors['relatedCourse'] = 'Related course must be 255 characters or fewer.';
        }

        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['contactEmail'] = 'Please enter a valid contact email.';
        }

        if ($applicationLink !== '' && !preg_match('#^https?://#i', $applicationLink)) {
            $errors['applicationLink'] = 'Application link must start with http:// or https://.';
        }

        $deadlineDate = null;

        if ($deadline !== '') {
            $deadlineDate = \DateTime::createFromFormat('Y-m-d', $deadline);

            if (!$deadlineDate instanceof \DateTimeInterface) {
                $errors['deadline'] = 'Please enter a valid deadline.';
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $job
            ->setTitle($title)
            ->setCompanyName($companyName)
            ->setLocation($location !== '' ? $location : null)
            ->setDescription($description)
            ->setRequirements($requirements !== '' ? $requirements : null)
            ->setSalaryRange($salaryRange !== '' ? $salaryRange : null)
            ->setEmploymentType($employmentType !== '' ? $employmentType : null)
            ->setIndustry($industry !== '' ? $industry : null)
            ->setRelatedCourse($relatedCourse !== '' ? $relatedCourse : null)
            ->setContactEmail($contactEmail !== '' ? $contactEmail : null)
            ->setApplicationLink($applicationLink !== '' ? $applicationLink : null)
            ->setDeadline($deadlineDate)
            ->setIsActive(filter_var($isActive, FILTER_VALIDATE_BOOL));

        return [];
    }

    private function serializeCollege(College $college): array
    {
        return [
            'id' => $college->getId(),
            'name' => $college->getName(),
            'code' => $college->getCode(),
            'description' => $college->getDescription(),
            'isActive' => $college->isIsActive(),
            'departmentCount' => $college->getDepartments()->count(),
        ];
    }

    private function serializeDepartment(Department $department): array
    {
        $college = $department->getCollege();

        return [
            'id' => $department->getId(),
            'name' => $department->getName(),
            'code' => $department->getCode(),
            'description' => $department->getDescription(),
            'isActive' => $department->isIsActive(),
            'college' => $college ? [
                'id' => $college->getId(),
                'name' => $college->getName(),
                'code' => $college->getCode(),
            ] : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function applySurveyTemplatePayload(GtsSurveyTemplate $template, Request $request): array
    {
        $payload = $this->jsonPayload($request);
        $title = trim((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $errors = [];

        if ($title === '') {
            $errors['title'] = 'Please enter a survey title.';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Survey title must be 255 characters or fewer.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $template
            ->setTitle($title)
            ->setDescription($description !== '' ? $description : null)
            ->setIsActive(filter_var($payload['isActive'] ?? true, FILTER_VALIDATE_BOOL));

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function applySurveyQuestionPayload(GtsSurveyQuestion $question, Request $request, GtsSurveyQuestionBank $questionBank): array
    {
        $payload = $this->jsonPayload($request);
        $questionText = trim((string) ($payload['questionText'] ?? ''));
        $inputType = strtolower(trim((string) ($payload['inputType'] ?? 'text')));
        $section = trim((string) ($payload['section'] ?? 'General'));
        $sortOrder = is_numeric($payload['sortOrder'] ?? null) ? (int) $payload['sortOrder'] : 0;
        $optionsText = (string) ($payload['optionsText'] ?? '');
        $errors = [];

        if ($questionText === '') {
            $errors['questionText'] = 'Please enter the question text.';
        }

        if (!in_array($inputType, ['text', 'textarea', 'radio', 'checkbox', 'select', 'date', 'repeater'], true)) {
            $errors['inputType'] = 'Input type is not supported.';
        }

        if ($section !== '' && mb_strlen($section) > 120) {
            $errors['section'] = 'Section must be 120 characters or fewer.';
        }

        if ($errors !== []) {
            return $errors;
        }

        $question
            ->setQuestionText($questionText)
            ->setInputType($inputType)
            ->setSection($section !== '' ? $section : 'General')
            ->setSortOrder($sortOrder)
            ->setIsActive(filter_var($payload['isActive'] ?? true, FILTER_VALIDATE_BOOL))
            ->setOptions($questionBank->parseOptionsCsv($inputType, $optionsText));

        return [];
    }

    private function serializeSurveyTemplate(GtsSurveyTemplate $template): array
    {
        return [
            'id' => $template->getId(),
            'title' => $template->getTitle(),
            'description' => $template->getDescription(),
            'isActive' => $template->isActive(),
            'createdAt' => $this->formatDate($template->getCreatedAt()),
            'questionCount' => $template->getQuestions()->count(),
            'campaignCount' => $template->getCampaigns()->count(),
        ];
    }

    private function serializeSurveyQuestion(GtsSurveyQuestion $question): array
    {
        return [
            'id' => $question->getId(),
            'questionText' => $question->getQuestionText(),
            'inputType' => $question->getInputType(),
            'section' => $question->getSection(),
            'options' => $question->getOptions(),
            'sortOrder' => $question->getSortOrder(),
            'isActive' => $question->isActive(),
        ];
    }

    private function serializeCampaign(SurveyCampaign $campaign, SurveyInvitationRepository $invitationRepo): array
    {
        return [
            'id' => $campaign->getId(),
            'name' => $campaign->getName(),
            'surveyTemplate' => [
                'id' => $campaign->getSurveyTemplate()->getId(),
                'title' => $campaign->getSurveyTemplate()->getTitle(),
            ],
            'emailSubject' => $campaign->getEmailSubject(),
            'emailBody' => $campaign->getEmailBody(),
            'targetGraduationYears' => $campaign->getTargetGraduationYears(),
            'targetCollege' => $campaign->getTargetCollege(),
            'targetCourse' => $campaign->getTargetCourse(),
            'expiryDays' => $campaign->getExpiryDays(),
            'status' => $campaign->getStatus(),
            'createdBy' => $campaign->getCreatedBy(),
            'createdAt' => $this->formatDate($campaign->getCreatedAt()),
            'sentAt' => $this->formatDate($campaign->getSentAt()),
            'scheduledSendAt' => $this->formatDate($campaign->getScheduledSendAt()),
            'invitations' => [
                'total' => $invitationRepo->countByCampaign($campaign),
                'queued' => $invitationRepo->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_QUEUED),
                'sent' => $invitationRepo->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_SENT),
                'opened' => $invitationRepo->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_OPENED),
                'completed' => $invitationRepo->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_COMPLETED),
                'expired' => $invitationRepo->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_EXPIRED),
                'failed' => $invitationRepo->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_FAILED),
            ],
        ];
    }

    private function serializeGtsResponseRow(
        GtsSurvey $survey,
        GtsSurveyQuestionBank $questionBank,
        ?GtsSurveyRepository $surveyRepo = null,
    ): array
    {
        $summary = $questionBank->extractListSummary($survey);
        $invitation = $this->resolveGtsResponseInvitation($survey, $surveyRepo);
        $campaign = $invitation?->getCampaign();
        $template = $survey->getSurveyTemplate();
        $targetBatchYear = $campaign?->getTargetBatchYear() ?? $survey->getUser()?->getAlumni()?->getYearGraduated();

        return [
            'id' => $survey->getId(),
            'respondent' => [
                'name' => $survey->getName(),
                'email' => $survey->getEmailAddress(),
                'userId' => $survey->getUser()?->getId(),
            ],
            'surveyTemplate' => $template ? [
                'id' => $template->getId(),
                'title' => $template->getTitle(),
            ] : null,
            'campaign' => $campaign ? [
                'id' => $campaign->getId(),
                'name' => $campaign->getName(),
            ] : null,
            'sourceLabel' => $campaign ? $campaign->getName() : 'Direct response',
            'targetBatchYear' => $targetBatchYear,
            'invitation' => $invitation ? [
                'id' => $invitation->getId(),
                'status' => $invitation->getStatus(),
                'sentAt' => $this->formatDate($invitation->getSentAt()),
                'openedAt' => $this->formatDate($invitation->getOpenedAt()),
                'completedAt' => $this->formatDate($invitation->getCompletedAt()),
                'expiresAt' => $this->formatDate($invitation->getExpiresAt()),
            ] : null,
            'summary' => $summary,
            'submittedAt' => $this->formatDate($survey->getCreatedAt()),
        ];
    }

    private function serializeGtsResponseDetail(
        GtsSurvey $survey,
        GtsSurveyQuestionBank $questionBank,
        ?GtsSurveyRepository $surveyRepo = null,
    ): array
    {
        $row = $this->serializeGtsResponseRow($survey, $questionBank, $surveyRepo);
        $dynamicSections = $questionBank->groupBySection($questionBank->getStoredResponseItems($survey->getDynamicAnswers()));

        return [
            ...$row,
            'respondent' => [
                ...$row['respondent'],
                'institutionCode' => $survey->getInstitutionCode(),
                'controlCode' => $survey->getControlCode(),
            ],
            'answerSections' => $dynamicSections !== [] ? $dynamicSections : $this->legacyGtsResponseSections($survey),
        ];
    }

    private function resolveGtsResponseInvitation(
        GtsSurvey $survey,
        ?GtsSurveyRepository $surveyRepo,
    ): ?SurveyInvitation {
        $invitation = $survey->getSurveyInvitation();
        if ($invitation instanceof SurveyInvitation || !$surveyRepo instanceof GtsSurveyRepository) {
            return $invitation;
        }

        return $surveyRepo->findBestInvitationForResponse($survey);
    }

    /**
     * @return list<array{title: string, items: list<array<string, mixed>>}>
     */
    private function legacyGtsResponseSections(GtsSurvey $survey): array
    {
        $sections = [
            [
                'title' => 'Respondent',
                'items' => [
                    $this->legacyGtsAnswer('Name', $survey->getName()),
                    $this->legacyGtsAnswer('Email', $survey->getEmailAddress()),
                    $this->legacyGtsAnswer('Permanent Address', $survey->getPermanentAddress()),
                    $this->legacyGtsAnswer('Telephone Number', $survey->getTelephoneNumber()),
                    $this->legacyGtsAnswer('Mobile Number', $survey->getMobileNumber()),
                    $this->legacyGtsAnswer('Civil Status', $survey->getCivilStatus()),
                    $this->legacyGtsAnswer('Sex', $survey->getSex()),
                    $this->legacyGtsAnswer('Birthday', $this->formatDate($survey->getBirthday(), 'Y-m-d')),
                    $this->legacyGtsAnswer('Region of Origin', $survey->getRegionOfOrigin()),
                    $this->legacyGtsAnswer('Province', $survey->getProvince()),
                    $this->legacyGtsAnswer('Location of Residence', $survey->getLocationOfResidence()),
                ],
            ],
            [
                'title' => 'Education and Training',
                'items' => [
                    $this->legacyGtsAnswer('Educational Attainment', $survey->getEducationalAttainment(), 'repeater'),
                    $this->legacyGtsAnswer('Professional Exams', $survey->getProfessionalExams(), 'repeater'),
                    $this->legacyGtsAnswer('Reasons for Course - Undergraduate', $survey->getReasonsForCourseUndergrad(), 'checkbox'),
                    $this->legacyGtsAnswer('Reasons for Course - Graduate', $survey->getReasonsForCourseGrad(), 'checkbox'),
                    $this->legacyGtsAnswer('Other Course Reason', $survey->getReasonsForCourseOther()),
                    $this->legacyGtsAnswer('Trainings', $survey->getTrainings(), 'repeater'),
                    $this->legacyGtsAnswer('Reasons for Advance Study', $survey->getReasonsAdvanceStudy(), 'checkbox'),
                    $this->legacyGtsAnswer('Other Advance Study Reason', $survey->getReasonAdvanceStudyOther()),
                ],
            ],
            [
                'title' => 'Employment',
                'items' => [
                    $this->legacyGtsAnswer('Presently Employed', $survey->getPresentlyEmployed()),
                    $this->legacyGtsAnswer('Reasons Not Employed', $survey->getReasonsNotEmployed(), 'checkbox'),
                    $this->legacyGtsAnswer('Other Not Employed Reason', $survey->getReasonNotEmployedOther()),
                    $this->legacyGtsAnswer('Present Employment Status', $survey->getPresentEmploymentStatus()),
                    $this->legacyGtsAnswer('Present Occupation', $survey->getPresentOccupation()),
                    $this->legacyGtsAnswer('Company Name and Address', $survey->getCompanyNameAddress(), 'textarea'),
                    $this->legacyGtsAnswer('Line of Business', $survey->getLineOfBusiness()),
                    $this->legacyGtsAnswer('Place of Work', $survey->getPlaceOfWork()),
                    $this->legacyGtsAnswer('First Job After College', $this->nullableBoolLabel($survey->isFirstJobAfterCollege())),
                    $this->legacyGtsAnswer('First Job Related to Course', $this->nullableBoolLabel($survey->isFirstJobRelatedToCourse())),
                    $this->legacyGtsAnswer('Initial Monthly Earning', $survey->getInitialMonthlyEarning()),
                    $this->legacyGtsAnswer('Curriculum Relevant', $this->nullableBoolLabel($survey->isCurriculumRelevant())),
                    $this->legacyGtsAnswer('Suggestions', $survey->getSuggestions(), 'textarea'),
                ],
            ],
        ];

        return array_values(array_filter(array_map(static function (array $section): ?array {
            $items = array_values(array_filter($section['items'], static fn (?array $item): bool => $item !== null));

            return $items !== [] ? ['title' => $section['title'], 'items' => $items] : null;
        }, $sections)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function legacyGtsAnswer(string $questionText, mixed $answer, string $inputType = 'text'): ?array
    {
        if ($answer === null || $answer === '' || $answer === []) {
            return null;
        }

        return [
            'key' => strtolower(preg_replace('/[^a-z0-9]+/i', '_', $questionText) ?? $questionText),
            'section' => 'Legacy Response',
            'questionText' => $questionText,
            'inputType' => $inputType,
            'options' => null,
            'numberKey' => null,
            'answer' => $answer,
        ];
    }

    private function nullableBoolLabel(?bool $value): ?string
    {
        return $value === null ? null : ($value ? 'Yes' : 'No');
    }

    private function serializeCampaignRecipient(Alumni $alumni): array
    {
        $user = $alumni->getUser();

        return [
            'id' => $alumni->getId(),
            'name' => $alumni->getFullName(),
            'email' => $user?->getEmail() ?: $alumni->getEmailAddress(),
            'studentNumber' => $alumni->getStudentNumber(),
            'college' => $alumni->getCollege(),
            'course' => $alumni->getCourse(),
            'yearGraduated' => $alumni->getYearGraduated(),
        ];
    }

    private function serializeQrBatch(QrRegistrationBatch $batch): array
    {
        return [
            'id' => $batch->getId(),
            'batchYear' => $batch->getBatchYear(),
            'isOpen' => $batch->isOpen(),
            'createdAt' => $this->formatDate($batch->getCreatedAt()),
            'registrationUrl' => $this->generateUrl(
                'app_qr_registration',
                ['batchYear' => $batch->getBatchYear()],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
        ];
    }

    private function serializeAuditLog(AuditLog $log): array
    {
        return [
            'id' => $log->getId(),
            'action' => $log->getAction(),
            'actionLabel' => $log->getActionLabel(),
            'entityType' => $log->getEntityType(),
            'entityId' => $log->getEntityId(),
            'details' => $log->getDetails(),
            'ipAddress' => $log->getIpAddress(),
            'createdAt' => $this->formatDate($log->getCreatedAt()),
            'performedBy' => [
                'id' => $log->getPerformedBy()->getId(),
                'fullName' => $log->getPerformedBy()->getFullName(),
                'email' => $log->getPerformedBy()->getEmail(),
            ],
        ];
    }

    private function primaryRole(User $user): string
    {
        $roles = $user->getRoles();

        return match (true) {
            in_array('ROLE_ADMIN', $roles, true) => 'admin',
            in_array('ROLE_STAFF', $roles, true) => 'staff',
            in_array(User::ROLE_ALUMNI, $roles, true) => 'alumni',
            default => 'user',
        };
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max(1, (int) $value);
    }

    private function nonNegativeInt(mixed $value, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max(0, (int) $value);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Admin account is required.');
        }

        return $user;
    }

    private function campaignTargetBatchYear(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $year = (int) $value;

        return $year >= 1900 && $year <= 2100 ? $year : null;
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function parseScheduledSendAt(mixed $value): ?\DateTimeImmutable
    {
        $rawValue = trim((string) $value);
        if ($rawValue === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($rawValue);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    private function formatDate(?\DateTimeInterface $date, string $format = \DateTimeInterface::ATOM): ?string
    {
        return $date?->format($format);
    }
}
