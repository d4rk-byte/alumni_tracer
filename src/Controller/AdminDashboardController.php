<?php

namespace App\Controller;

use App\Repository\AlumniRepository;
use App\Repository\AnnouncementRepository;
use App\Repository\AuditLogRepository;
use App\Repository\GtsSurveyRepository;
use App\Repository\JobPostingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminDashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'admin_dashboard', methods: ['GET'])]
    public function index(
        AlumniRepository $alumniRepo,
        UserRepository $userRepo,
        JobPostingRepository $jobRepo,
        AnnouncementRepository $announcementRepo,
        GtsSurveyRepository $gtsRepo,
        AuditLogRepository $auditLogRepo,
        EntityManagerInterface $em,
        CacheInterface $cache,
    ): Response {
        $stats = $cache->get('admin_dashboard_common_stats', function (ItemInterface $item) use ($alumniRepo, $userRepo) {
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

        $collegeStats = $alumniRepo->createQueryBuilder('a')
            ->select("COALESCE(NULLIF(TRIM(a.college), ''), :unknown) AS label")
            ->addSelect('COUNT(a.id) AS total')
            ->setParameter('unknown', 'Unspecified')
            ->groupBy('label')
            ->orderBy('total', 'DESC')
            ->setMaxResults(6)
            ->getQuery()
            ->getArrayResult();

        $departmentStats = $alumniRepo->createQueryBuilder('a')
            ->select("COALESCE(NULLIF(TRIM(a.major), ''), NULLIF(TRIM(a.degreeProgram), ''), :unknown) AS label")
            ->addSelect('COUNT(a.id) AS total')
            ->setParameter('unknown', 'Unspecified')
            ->groupBy('label')
            ->orderBy('total', 'DESC')
            ->setMaxResults(6)
            ->getQuery()
            ->getArrayResult();

        $batchYearStats = $alumniRepo->createQueryBuilder('a')
            ->select('a.yearGraduated AS label')
            ->addSelect('COUNT(a.id) AS total')
            ->where('a.yearGraduated IS NOT NULL')
            ->groupBy('a.yearGraduated')
            ->orderBy('a.yearGraduated', 'DESC')
            ->setMaxResults(6)
            ->getQuery()
            ->getArrayResult();

        $employmentStatusRows = $alumniRepo->countGroupedByEmploymentStatus();
        $employmentStatusChart = [
            'labels' => array_column($employmentStatusRows, 'employmentStatus'),
            'values' => array_column($employmentStatusRows, 'total'),
        ];

        $pendingUsers = $userRepo->count(['accountStatus' => 'pending']);
        $pendingList = $userRepo->findBy(['accountStatus' => 'pending'], ['dateRegistered' => 'DESC'], 5);
        $activeJobs = $jobRepo->count(['isActive' => true]);
        $totalJobs = $jobRepo->count([]);
        $totalSurveyResponses = $gtsRepo->count([]);

        $jobRelated = $alumniRepo->count(['jobRelatedToCourse' => true]);
        $employedTotal = $employed + $selfEmployed;
        $alignmentRate = $employedTotal > 0 ? round($jobRelated / $employedTotal * 100, 1) : 0;

        $recentAlumni = $alumniRepo->findBy([], ['id' => 'DESC'], 5);
        $recentAnnouncements = $announcementRepo->findBy([], ['datePosted' => 'DESC'], 5);
        $recentGtsResponses = $gtsRepo->createQueryBuilder('s')
            ->leftJoin('s.user', 'u')
            ->addSelect('u')
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(6)
            ->getQuery()
            ->getResult();

        $adminCount = $userRepo->countUsersWithRole('ROLE_ADMIN');
        $staffCount = $userRepo->countUsersWithRole('ROLE_STAFF');
        $studentCount = $totalUsers - $adminCount - $staffCount;

        $threshold = (new \DateTime())->modify('-5 minutes');
        $activeUsers = (int) $em->createQuery(
            'SELECT COUNT(u.id) FROM App\\Entity\\User u WHERE u.lastActivity IS NOT NULL AND u.lastActivity > :threshold'
        )->setParameter('threshold', $threshold)->getSingleScalarResult();

        return $this->render('admin/dashboard.html.twig', [
            'totalAlumni' => $totalAlumni,
            'employed' => $employed,
            'unemployed' => $unemployed,
            'selfEmployed' => $selfEmployed,
            'totalUsers' => $totalUsers,
            'employmentRate' => $employmentRate,
            'registrationStates' => $registrationStates,
            'recentAlumni' => $recentAlumni,
            'courseStats' => $courseStats,
            'collegeStats' => $collegeStats,
            'departmentStats' => $departmentStats,
            'batchYearStats' => $batchYearStats,
            'pendingUsers' => $pendingUsers,
            'pendingList' => $pendingList,
            'activeJobs' => $activeJobs,
            'totalJobs' => $totalJobs,
            'totalSurveyResponses' => $totalSurveyResponses,
            'alignmentRate' => $alignmentRate,
            'recentAnnouncements' => $recentAnnouncements,
            'recentGtsResponses' => $recentGtsResponses,
            'recentAuditLogs' => $auditLogRepo->findRecent(10),
            'adminCount' => $adminCount,
            'staffCount' => $staffCount,
            'studentCount' => $studentCount,
            'activeUsers' => $activeUsers,
            'employmentStatusChart' => $employmentStatusChart,
        ]);
    }
}
