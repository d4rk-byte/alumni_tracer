<?php

namespace App\Controller;

use App\Repository\AlumniRepository;
use App\Repository\AnnouncementRepository;
use App\Repository\GtsSurveyRepository;
use App\Repository\JobPostingRepository;
use App\Repository\UserRepository;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_root')]
    public function root(): Response
    {
        return $this->redirectToRoute('app_home');
    }

    #[Route('/about', name: 'app_about', methods: ['GET'])]
    public function about(): Response
    {
        return $this->render('home/about.html.twig');
    }

    #[Route('/contact-us', name: 'app_contact', methods: ['GET'])]
    public function contact(): Response
    {
        return $this->render('home/contact.html.twig');
    }

    #[Route('/faq', name: 'app_faq', methods: ['GET'])]
    public function faq(): Response
    {
        return $this->render('home/faq.html.twig');
    }

    #[Route('/privacy', name: 'app_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('home/privacy.html.twig');
    }

    #[Route('/terms-and-conditions', name: 'app_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('home/terms.html.twig');
    }

    #[Route('/logos', name: 'app_logos', methods: ['GET'])]
    public function logos(): Response
    {
        return $this->render('home/logos.html.twig', [
            'logos' => $this->collectLogos(),
        ]);
    }

    #[Route('/features/announcements', name: 'app_feature_announcements', methods: ['GET'])]
    public function featureAnnouncements(): Response
    {
        return $this->render('home/feature_announcements.html.twig');
    }

    #[Route('/features/tracer-survey', name: 'app_feature_tracer_survey', methods: ['GET'])]
    public function featureTracerSurvey(): Response
    {
        return $this->render('home/feature_tracer_survey.html.twig');
    }

    #[Route('/features/career-opportunities', name: 'app_feature_career_opportunities', methods: ['GET'])]
    public function featureCareerOpportunities(): Response
    {
        return $this->render('home/feature_career_opportunities.html.twig');
    }

    #[Route('/home', name: 'app_home')]
    public function index(AlumniRepository $alumniRepo, UserRepository $userRepo, JobPostingRepository $jobRepo, AnnouncementRepository $announcementRepo, GtsSurveyRepository $gtsRepo, CacheInterface $cache): Response
    {
        if (!$this->getUser()) {
            return $this->render('home/landing.html.twig', [
                'logos' => $this->collectLogos(),
            ]);
        }

        // Dedicated dashboards keep module logic isolated per role.
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($this->isGranted('ROLE_STAFF')) {
            return $this->redirectToRoute('staff_dashboard');
        }

        // Common stats (cached for 5 minutes)
        $stats = $cache->get('dashboard_common_stats', function (ItemInterface $item) use ($alumniRepo, $userRepo) {
            $item->expiresAfter(300);
            $totalAlumni = $alumniRepo->count([]);
            $employed = $alumniRepo->count(['employmentStatus' => 'Employed']);
            $unemployed = $alumniRepo->count(['employmentStatus' => 'Unemployed']);
            $selfEmployed = $alumniRepo->count(['employmentStatus' => 'Self-Employed']);
            $totalUsers = $userRepo->count([]);
            $employmentRate = $totalAlumni > 0 ? round(($employed + $selfEmployed) / $totalAlumni * 100, 1) : 0;
            return compact('totalAlumni', 'employed', 'unemployed', 'selfEmployed', 'totalUsers', 'employmentRate');
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

        // Alumni dashboard (ROLE_ALUMNI)
        if ($this->isGranted('ROLE_ALUMNI')) {
            $user = $this->getUser();
            if (!$user instanceof User) {
                return $this->redirectToRoute('app_login');
            }

            $alumni = $user->getAlumni();

            $recentAnnouncements = $announcementRepo->findBy(['isActive' => true], ['datePosted' => 'DESC'], 9);
            $recentJobs = $jobRepo->findBy(['isActive' => true], ['datePosted' => 'DESC'], 8);
            $milestoneAlumni = $alumniRepo->createQueryBuilder('a')
                ->where('a.deletedAt IS NULL')
                ->andWhere("(a.honorsReceived IS NOT NULL AND a.honorsReceived <> '') OR (a.careerAchievements IS NOT NULL AND a.careerAchievements <> '') OR (a.jobTitle IS NOT NULL AND a.jobTitle <> '')")
                ->orderBy('a.yearGraduated', 'DESC')
                ->addOrderBy('a.id', 'DESC')
                ->setMaxResults(4)
                ->getQuery()
                ->getResult();
            $hasGtsSurvey = $gtsRepo->count(['user' => $user]) > 0;

            return $this->render('home/alumni_dashboard.html.twig', [
                'alumni' => $alumni,
                'recentAnnouncements' => $recentAnnouncements,
                'recentJobs' => $recentJobs,
                'milestoneAlumni' => $milestoneAlumni,
                'hasGtsSurvey' => $hasGtsSurvey,
            ]);
        }

        // Student / default dashboard
        $activeJobs = $jobRepo->count(['isActive' => true]);

        return $this->render('home/student_dashboard.html.twig', [
            'activeJobs' => $activeJobs,
            'employmentRate' => $employmentRate,
            'courseStats' => $courseStats,
        ]);
    }

    /**
     * @return array<int, array{name: string, assetPath: string}>
     */
    private function collectLogos(): array
    {
        $logosDir = (string) $this->getParameter('kernel.project_dir') . '/public/skilline/img/logos';
        $files = glob($logosDir . '/*.{png,jpg,jpeg,svg,webp,gif}', GLOB_BRACE) ?: [];

        $logos = array_map(static function (string $file): array {
            $filename = basename($file);
            $name = pathinfo($filename, PATHINFO_FILENAME);

            return [
                'name' => str_replace(['-', '_'], ' ', $name),
                'assetPath' => 'skilline/img/logos/' . $filename,
            ];
        }, $files);

        usort($logos, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $logos;
    }
}
