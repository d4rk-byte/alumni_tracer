<?php

namespace App\Controller;

use App\Repository\AlumniRepository;
use App\Repository\AnnouncementRepository;
use App\Repository\AuditLogRepository;
use App\Repository\JobPostingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/staff')]
#[IsGranted('ROLE_STAFF')]
class StaffController extends AbstractController
{
    #[Route('', name: 'staff_dashboard')]
    public function dashboard(
        AlumniRepository $alumniRepo,
        UserRepository $userRepo,
        JobPostingRepository $jobRepo,
        AnnouncementRepository $announcementRepo,
        AuditLogRepository $auditLogRepo,
        EntityManagerInterface $em,
        CacheInterface $cache,
    ): Response {
        // Cached stats (5 minutes)
        $stats = $cache->get('staff_dashboard_stats', function (ItemInterface $item) use ($alumniRepo, $userRepo) {
            $item->expiresAfter(300);
            $totalAlumni = $alumniRepo->count([]);
            $employed = $alumniRepo->count(['employmentStatus' => 'Employed']);
            $unemployed = $alumniRepo->count(['employmentStatus' => 'Unemployed']);
            $selfEmployed = $alumniRepo->count(['employmentStatus' => 'Self-Employed']);
            $totalUsers = $userRepo->count([]);
            $employmentRate = $totalAlumni > 0 ? round(($employed + $selfEmployed) / $totalAlumni * 100, 1) : 0;
            $registrationStates = $alumniRepo->countRegistrationStates();

            return compact('totalAlumni', 'employed', 'unemployed', 'selfEmployed', 'totalUsers', 'employmentRate', 'registrationStates');
        });
        extract($stats);

        $courseStats = $alumniRepo->createQueryBuilder('a')
            ->select('a.course, COUNT(a.id) as total')
            ->where('a.course IS NOT NULL')
            ->groupBy('a.course')
            ->orderBy('total', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        $pendingUsers = $userRepo->count(['accountStatus' => 'pending']);
        $pendingList = $userRepo->findBy(['accountStatus' => 'pending'], ['dateRegistered' => 'DESC'], 5);
        $activeJobs = $jobRepo->count(['isActive' => true]);
        $totalJobs = $jobRepo->count([]);

        // Job-course alignment rate
        $jobRelated = $alumniRepo->count(['jobRelatedToCourse' => true]);
        $employedTotal = $employed + $selfEmployed;
        $alignmentRate = $employedTotal > 0 ? round($jobRelated / $employedTotal * 100, 1) : 0;

        $recentAlumni = $alumniRepo->findBy([], ['id' => 'DESC'], 5);
        $recentAnnouncements = $announcementRepo->findBy([], ['datePosted' => 'DESC'], 5);
        $recentAuditLogs = $auditLogRepo->findBy([], ['createdAt' => 'DESC'], 5);

        $fullyTracedCount = (int) $alumniRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.tracerStatus = :tracerStatus')
            ->setParameter('tracerStatus', 'Fully Traced')
            ->getQuery()
            ->getSingleScalarResult();

        $freshWindow = (new \DateTime())->modify('-30 days');
        $freshSubmissionCount = (int) $alumniRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.lastTracerSubmissionAt IS NOT NULL')
            ->andWhere('a.lastTracerSubmissionAt >= :freshWindow')
            ->setParameter('freshWindow', $freshWindow)
            ->getQuery()
            ->getSingleScalarResult();

        $recentSurveySubmissions = (int) $em->createQuery(
            'SELECT COUNT(s.id) FROM App\\Entity\\GtsSurvey s WHERE s.createdAt >= :freshWindow'
        )->setParameter('freshWindow', $freshWindow)->getSingleScalarResult();

        $topCourse = $courseStats[0]['course'] ?? 'N/A';
        $quickReport = sprintf(
            'Total Alumni: %d | Active Accounts: %d | Pending Approval: %d | Unregistered: %d | Employment Rate: %.1f%% | Top Course: %s',
            $totalAlumni,
            $registrationStates[AlumniRepository::REGISTRATION_STATE_ACTIVE] ?? 0,
            $registrationStates[AlumniRepository::REGISTRATION_STATE_PENDING] ?? 0,
            $registrationStates[AlumniRepository::REGISTRATION_STATE_UNREGISTERED] ?? 0,
            $employmentRate,
            $topCourse
        );

        // Role counts
        $adminCount = $userRepo->countUsersWithRole('ROLE_ADMIN');
        $staffCount = $userRepo->countUsersWithRole('ROLE_STAFF');
        $alumniCount = $totalUsers - $adminCount - $staffCount;

        // Online users
        $threshold = (new \DateTime())->modify('-5 minutes');
        $activeUsers = (int) $em->createQuery(
            'SELECT COUNT(u.id) FROM App\Entity\User u WHERE u.lastActivity IS NOT NULL AND u.lastActivity > :threshold'
        )->setParameter('threshold', $threshold)->getSingleScalarResult();

        return $this->render('staff/dashboard.html.twig', [
            'totalAlumni' => $totalAlumni,
            'employed' => $employed,
            'unemployed' => $unemployed,
            'selfEmployed' => $selfEmployed,
            'totalUsers' => $totalUsers,
            'employmentRate' => $employmentRate,
            'registrationStates' => $registrationStates,
            'courseStats' => $courseStats,
            'pendingUsers' => $pendingUsers,
            'pendingList' => $pendingList,
            'activeJobs' => $activeJobs,
            'totalJobs' => $totalJobs,
            'alignmentRate' => $alignmentRate,
            'recentAlumni' => $recentAlumni,
            'recentAnnouncements' => $recentAnnouncements,
            'recentAuditLogs' => $recentAuditLogs,
            'adminCount' => $adminCount,
            'staffCount' => $staffCount,
            'alumniCount' => $alumniCount,
            'activeUsers' => $activeUsers,
            'fullyTracedCount' => $fullyTracedCount,
            'freshSubmissionCount' => $freshSubmissionCount,
            'recentSurveySubmissions' => $recentSurveySubmissions,
            'quickReport' => $quickReport,
        ]);
    }
}
