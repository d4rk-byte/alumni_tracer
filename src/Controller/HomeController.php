<?php

namespace App\Controller;

use App\Entity\Alumni;
use App\Entity\GtsSurvey;
use App\Entity\SurveyInvitation;
use App\Repository\AlumniRepository;
use App\Repository\AnnouncementRepository;
use App\Repository\GtsSurveyRepository;
use App\Repository\JobPostingRepository;
use App\Repository\SurveyInvitationRepository;
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

    #[Route('/alumni-home/announcements', name: 'app_alumni_feature_announcements', methods: ['GET'], defaults: ['feature' => 'announcements'])]
    #[Route('/alumni-home/jobs', name: 'app_alumni_feature_jobs', methods: ['GET'], defaults: ['feature' => 'jobs'])]
    #[Route('/alumni-home/tracer-survey', name: 'app_alumni_feature_tracer_survey', methods: ['GET'], defaults: ['feature' => 'tracer'])]
    #[Route('/alumni-home/my-profile', name: 'app_alumni_feature_profile', methods: ['GET'], defaults: ['feature' => 'profile'])]
    public function alumniFeaturePage(string $feature, AlumniRepository $alumniRepo, AnnouncementRepository $announcementRepo, JobPostingRepository $jobRepo, GtsSurveyRepository $gtsRepo, SurveyInvitationRepository $surveyInvitationRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ALUMNI');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $context = array_merge(
            $this->buildAlumniLandingContext($user, $alumniRepo, $announcementRepo, $jobRepo, $gtsRepo, $surveyInvitationRepo),
            match ($feature) {
                'announcements' => [
                    'announcementFeed' => $announcementRepo->findActiveAnnouncements(6),
                ],
                'jobs' => [
                    'jobFeed' => array_slice($jobRepo->findActiveJobs(), 0, 6),
                ],
                'tracer' => [
                    'latestSurvey' => $gtsRepo->findLatestByUser($user),
                ],
                'profile' => [
                    'latestSurvey' => $gtsRepo->findLatestByUser($user),
                ],
                default => [],
            },
        );

        return $this->render('home/alumni_feature_page.html.twig', array_merge(
            $context,
            $this->buildAlumniFeaturePageConfig($feature, $context),
        ));
    }

    #[Route('/home', name: 'app_home')]
    public function index(AlumniRepository $alumniRepo, UserRepository $userRepo, JobPostingRepository $jobRepo, AnnouncementRepository $announcementRepo, GtsSurveyRepository $gtsRepo, SurveyInvitationRepository $surveyInvitationRepo, CacheInterface $cache): Response
    {
        if (!$this->getUser()) {
            return $this->render('home/landing.html.twig', [
                'logos' => $this->collectLogos(),
                'landing_mode' => 'guest',
            ]);
        }

        // Dedicated dashboards keep module logic isolated per role.
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($this->isGranted('ROLE_STAFF')) {
            return $this->redirectToRoute('staff_dashboard');
        }

        if ($this->isGranted('ROLE_ALUMNI')) {
            $user = $this->getUser();
            if (!$user instanceof User) {
                return $this->redirectToRoute('app_login');
            }

            return $this->render('home/landing.html.twig', $this->buildAlumniLandingContext($user, $alumniRepo, $announcementRepo, $jobRepo, $gtsRepo, $surveyInvitationRepo));
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

        // Student / default dashboard
        $activeJobs = $jobRepo->count(['isActive' => true]);

        return $this->render('home/student_dashboard.html.twig', [
            'activeJobs' => $activeJobs,
            'employmentRate' => $employmentRate,
            'courseStats' => $courseStats,
        ]);
    }

    /**
     * @return array{
     *   logos: array<int, array{name: string, assetPath: string}>,
     *   landing_mode: string,
     *   alumni: ?Alumni,
     *   recentAnnouncements: array<int, object>,
     *   recentJobs: array<int, object>,
    *   latestSurvey: ?\App\Entity\GtsSurvey,
    *   tracerCampaignSnapshot: array{hasInvitation: bool, hasCampaign: bool, campaignName: string, campaignStatus: ?string, campaignStatusLabel: string, invitationStatus: ?string, invitationStatusLabel: string, statusSummary: string, timelineLabel: string, timelineValue: string, windowValue: string, audienceSummary: string},
     *   milestoneAlumni: array<int, Alumni>,
     *   hasGtsSurvey: bool,
     *   profileSnapshot: array{
     *     completionPercent: int,
     *     completionStatus: string,
     *     accountStatus: string,
     *     tracerStatus: string,
     *     dateRegistered: \DateTimeInterface,
     *     lastLogin: ?\DateTimeInterface,
     *     profileCompletedAt: ?\DateTimeImmutable,
     *     lastTracerSubmissionAt: ?\DateTimeInterface
     *   }
     * }
     */
    private function buildAlumniLandingContext(User $user, AlumniRepository $alumniRepo, AnnouncementRepository $announcementRepo, JobPostingRepository $jobRepo, GtsSurveyRepository $gtsRepo, SurveyInvitationRepository $surveyInvitationRepo): array
    {
        $alumni = $user->getAlumni();
        $recentAnnouncements = $announcementRepo->findActiveAnnouncements(3);
        $recentJobs = array_slice($jobRepo->findActiveJobs(), 0, 4);
        $latestSurvey = $gtsRepo->findLatestByUser($user);
        $tracerCampaignSnapshot = $this->buildTracerCampaignSnapshot($user, $latestSurvey, $surveyInvitationRepo);
        $milestoneAlumni = $alumniRepo->createQueryBuilder('a')
            ->where('a.deletedAt IS NULL')
            ->andWhere("(a.honorsReceived IS NOT NULL AND a.honorsReceived <> '') OR (a.careerAchievements IS NOT NULL AND a.careerAchievements <> '') OR (a.jobTitle IS NOT NULL AND a.jobTitle <> '')")
            ->orderBy('a.yearGraduated', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();
        $hasGtsSurvey = $latestSurvey !== null;

        return [
            'logos' => $this->collectLogos(),
            'landing_mode' => 'alumni',
            'alumni' => $alumni,
            'recentAnnouncements' => $recentAnnouncements,
            'recentJobs' => $recentJobs,
            'latestSurvey' => $latestSurvey,
            'tracerCampaignSnapshot' => $tracerCampaignSnapshot,
            'milestoneAlumni' => $milestoneAlumni,
            'hasGtsSurvey' => $hasGtsSurvey,
            'profileSnapshot' => $this->buildAlumniProfileSnapshot($user, $alumni, $hasGtsSurvey),
        ];
    }

    /**
    * @param array{
     *   alumni: ?Alumni,
     *   recentAnnouncements: array<int, object>,
     *   recentJobs: array<int, object>,
    *   milestoneAlumni: array<int, Alumni>,
    *   hasGtsSurvey: bool,
    *   profileSnapshot: array{completionPercent: int, completionStatus: string, accountStatus: string, tracerStatus: string, dateRegistered: \DateTimeInterface, lastLogin: ?\DateTimeInterface, profileCompletedAt: ?\DateTimeImmutable, lastTracerSubmissionAt: ?\DateTimeInterface},
    *   tracerCampaignSnapshot: array{hasInvitation: bool, hasCampaign: bool, campaignName: string, campaignStatus: ?string, campaignStatusLabel: string, invitationStatus: ?string, invitationStatusLabel: string, statusSummary: string, timelineLabel: string, timelineValue: string, windowValue: string, audienceSummary: string}
     * } $context
     *
    * @return array{
    *   featureKey: string,
     *   pageEyebrow: string,
     *   pageTitle: string,
     *   pageDescription: string,
     *   pagePills: array<int, string>,
     *   pageCards: array<int, array{eyebrow: string, title: string, body: string}>,
     *   primaryAction: array{href: string, label: string},
     *   secondaryAction: array{href: string, label: string},
     *   nextStep: array{title: string, body: string},
     *   pageModuleHint: string
     * }
     */
    private function buildAlumniFeaturePageConfig(string $feature, array $context): array
    {
        $spotlightAlumnus = $context['milestoneAlumni'][0] ?? null;
        $profileSnapshot = $context['profileSnapshot'];
        $hasLinkedAlumni = $context['alumni'] instanceof Alumni;

        return match ($feature) {
            'announcements' => $this->buildAlumniAnnouncementsFeaturePageConfig($context),
            'jobs' => $this->buildAlumniJobsFeaturePageConfig($context),
            'tracer' => $this->buildAlumniTracerFeaturePageConfig($context),
            'profile' => $this->buildAlumniProfileFeaturePageConfig($context),
            default => throw $this->createNotFoundException(),
        };
    }

    /**
     * @param array{announcementFeed?: array<int, object>, hasGtsSurvey: bool, profileSnapshot: array{accountStatus: string}} $context
     *
     * @return array{
     *   featureKey: string,
     *   pageEyebrow: string,
     *   pageTitle: string,
     *   pageDescription: string,
     *   pagePills: array<int, string>,
     *   pageCards: array<int, array{eyebrow: string, title: string, body: string}>,
     *   primaryAction: array{href: string, label: string},
     *   secondaryAction: array{href: string, label: string},
     *   nextStep: array{title: string, body: string},
     *   pageModuleHint: string
     * }
     */
    private function buildAlumniAnnouncementsFeaturePageConfig(array $context): array
    {
        $announcementFeed = $context['announcementFeed'] ?? [];
        $featuredAnnouncement = $announcementFeed[0] ?? null;
        $latestCategory = $featuredAnnouncement?->getCategory() ?: 'Office updates';

        return [
            'featureKey' => 'announcements',
            'pageEyebrow' => 'ANNOUNCEMENT DESK',
            'pageTitle' => 'Announcements and Updates',
            'pageDescription' => 'Review active alumni notices, office advisories, and event updates in a full landing page view without handing off to the module screens.',
            'pagePills' => [
                count($announcementFeed) . ' active announcements',
                $latestCategory,
                $context['hasGtsSurvey'] ? 'Survey completed' : 'Survey pending',
            ],
            'pageCards' => [],
            'primaryAction' => [
                'href' => $this->generateUrl('app_alumni_feature_announcements') . '#announcement-board',
                'label' => 'Jump to notice board',
            ],
            'secondaryAction' => [
                'href' => $this->generateUrl('app_alumni_feature_jobs'),
                'label' => 'Go to jobs page',
            ],
            'nextStep' => [
                'title' => 'Continue with another alumni page',
                'body' => 'After reviewing campus and alumni office notices, move to jobs, tracer, or profile without leaving the landing experience.',
            ],
            'pageModuleHint' => $featuredAnnouncement
                ? ('Latest notice posted on ' . $featuredAnnouncement->getDatePosted()->format('M d, Y') . ' for alumni readers.')
                : 'This announcements page stays inside the landing experience and shows active office notices only.',
        ];
    }

    /**
     * @param array{jobFeed?: array<int, object>, hasGtsSurvey: bool, profileSnapshot: array{completionPercent: int}} $context
     *
     * @return array{
     *   featureKey: string,
     *   pageEyebrow: string,
     *   pageTitle: string,
     *   pageDescription: string,
     *   pagePills: array<int, string>,
     *   pageCards: array<int, array{eyebrow: string, title: string, body: string}>,
     *   primaryAction: array{href: string, label: string},
     *   secondaryAction: array{href: string, label: string},
     *   nextStep: array{title: string, body: string},
     *   pageModuleHint: string
     * }
     */
    private function buildAlumniJobsFeaturePageConfig(array $context): array
    {
        $jobFeed = $context['jobFeed'] ?? [];
        $featuredJob = $jobFeed[0] ?? null;
        $courseHint = $featuredJob?->getRelatedCourse() ?: 'Open to alumni-ready roles';

        return [
            'featureKey' => 'jobs',
            'pageEyebrow' => 'CAREER BOARD',
            'pageTitle' => 'Jobs and Career Opportunities',
            'pageDescription' => 'Browse active, non-expired job opportunities in a full landing page view with the key role details alumni need before taking the next step.',
            'pagePills' => [
                count($jobFeed) . ' active opportunities',
                $courseHint,
                $context['hasGtsSurvey'] ? 'Profile ready for follow-up' : 'Survey still pending',
            ],
            'pageCards' => [],
            'primaryAction' => [
                'href' => $this->generateUrl('app_alumni_feature_jobs') . '#career-board',
                'label' => 'Jump to opportunity board',
            ],
            'secondaryAction' => [
                'href' => $this->generateUrl($context['hasGtsSurvey'] ? 'app_alumni_feature_profile' : 'app_alumni_feature_tracer_survey'),
                'label' => $context['hasGtsSurvey'] ? 'Go to profile page' : 'Go to tracer page',
            ],
            'nextStep' => [
                'title' => 'Keep your profile opportunity-ready',
                'body' => 'Use the landing pages to review jobs, then keep your tracer and profile details current without jumping into the module dashboard.',
            ],
            'pageModuleHint' => $featuredJob
                ? ('Latest opportunity from ' . $featuredJob->getCompanyName() . ($featuredJob->getLocation() ? ' in ' . $featuredJob->getLocation() : '') . '.')
                : ('Your profile is currently ' . $context['profileSnapshot']['completionPercent'] . '% complete for opportunity follow-up.'),
        ];
    }

    /**
    * @param array{latestSurvey?: ?object, hasGtsSurvey: bool, profileSnapshot: array{completionPercent: int, tracerStatus: string, lastTracerSubmissionAt: ?\DateTimeInterface}, tracerCampaignSnapshot: array{hasInvitation: bool, hasCampaign: bool, campaignName: string, campaignStatus: ?string, campaignStatusLabel: string, invitationStatus: ?string, invitationStatusLabel: string, statusSummary: string, timelineLabel: string, timelineValue: string, windowValue: string, audienceSummary: string, entryUrl: ?string, entryLabel: ?string}} $context
     *
     * @return array{
     *   featureKey: string,
     *   pageEyebrow: string,
     *   pageTitle: string,
     *   pageDescription: string,
     *   pagePills: array<int, string>,
     *   pageCards: array<int, array{eyebrow: string, title: string, body: string}>,
     *   primaryAction: array{href: string, label: string},
     *   secondaryAction: array{href: string, label: string},
     *   nextStep: array{title: string, body: string},
     *   pageModuleHint: string
     * }
     */
    private function buildAlumniTracerFeaturePageConfig(array $context): array
    {
        $latestSurvey = $context['latestSurvey'] ?? null;
        $lastSubmittedAt = $context['profileSnapshot']['lastTracerSubmissionAt'];
        $tracerCampaignSnapshot = $context['tracerCampaignSnapshot'];
        $hasTracerEntry = is_string($tracerCampaignSnapshot['entryUrl']) && $tracerCampaignSnapshot['entryUrl'] !== '';

        [$nextStepTitle, $nextStepBody] = match ($tracerCampaignSnapshot['invitationStatus']) {
            SurveyInvitation::STATUS_OPENED => [
                'Your invitation is already open',
                'Resume your tracer response while the invitation window is still active, then keep your profile details aligned with the latest campaign.',
            ],
            SurveyInvitation::STATUS_EXPIRED => [
                'The latest invitation window has closed',
                'Watch for the next tracer campaign or contact the alumni office if you still need access to a new invitation cycle.',
            ],
            SurveyInvitation::STATUS_COMPLETED => [
                'Keep your profile updated',
                'Your latest tracer invitation is already completed, so the next useful step is keeping your profile and alumni record current inside the landing pages.',
            ],
            default => [
                $context['hasGtsSurvey'] ? 'Keep your profile updated' : 'Prepare your alumni profile before the next tracer step',
                $context['hasGtsSurvey']
                    ? 'Your latest tracer response is already saved, so the next useful step is keeping your profile and alumni record current inside the landing pages.'
                    : 'This page keeps your tracer status visible while you move between profile, jobs, and announcements without opening the survey module.',
            ],
        };

        return [
            'featureKey' => 'tracer',
            'pageEyebrow' => 'TRACER CONTROL CENTER',
            'pageTitle' => 'Tracer Survey Status',
            'pageDescription' => 'Review your tracer participation, last response details, and next steps from a landing-only page that stays outside the survey module screens.',
            'pagePills' => [
                $tracerCampaignSnapshot['invitationStatusLabel'] . ' invitation',
                $tracerCampaignSnapshot['campaignStatusLabel'] . ' campaign',
                $context['profileSnapshot']['completionPercent'] . '% profile completion',
            ],
            'pageCards' => [],
            'primaryAction' => [
                'href' => $hasTracerEntry ? $tracerCampaignSnapshot['entryUrl'] : ($this->generateUrl('app_alumni_feature_tracer_survey') . '#tracer-summary'),
                'label' => $hasTracerEntry ? ($tracerCampaignSnapshot['entryLabel'] ?? 'Open tracer form') : 'Review tracer summary',
            ],
            'secondaryAction' => [
                'href' => $this->generateUrl('app_alumni_feature_profile'),
                'label' => 'Go to profile page',
            ],
            'nextStep' => [
                'title' => $nextStepTitle,
                'body' => $nextStepBody,
            ],
            'pageModuleHint' => $tracerCampaignSnapshot['hasCampaign']
                ? ($tracerCampaignSnapshot['campaignName'] . ' is currently ' . strtolower($tracerCampaignSnapshot['campaignStatusLabel']) . ' with a ' . strtolower($tracerCampaignSnapshot['invitationStatusLabel']) . ' invitation state.')
                : ($lastSubmittedAt
                    ? ('Latest tracer response recorded on ' . $lastSubmittedAt->format('M d, Y') . '.')
                    : ($latestSurvey ? 'A tracer response is already linked to your account.' : 'No tracer response has been saved for this account yet.')),
        ];
    }

    /**
    * @return array{hasInvitation: bool, hasCampaign: bool, campaignName: string, campaignStatus: ?string, campaignStatusLabel: string, invitationStatus: ?string, invitationStatusLabel: string, statusSummary: string, timelineLabel: string, timelineValue: string, windowValue: string, audienceSummary: string, entryUrl: ?string, entryLabel: ?string}
     */
    private function buildTracerCampaignSnapshot(User $user, ?GtsSurvey $latestSurvey, SurveyInvitationRepository $surveyInvitationRepo): array
    {
        $pendingInvitation = $surveyInvitationRepo->findPendingForUser($user)[0] ?? null;
        $surveyInvitation = $latestSurvey?->getSurveyInvitation();
        $currentInvitation = $pendingInvitation instanceof SurveyInvitation
            ? $pendingInvitation
            : ($surveyInvitation instanceof SurveyInvitation ? $surveyInvitation : $surveyInvitationRepo->findLatestForUser($user));

        $campaign = $currentInvitation?->getCampaign();
        $invitationStatus = $this->resolveTracerInvitationStatus($currentInvitation, $latestSurvey);
        $invitationStatusLabel = match ($invitationStatus) {
            SurveyInvitation::STATUS_QUEUED => 'Queued',
            SurveyInvitation::STATUS_SENT => 'Sent',
            SurveyInvitation::STATUS_OPENED => 'Opened',
            SurveyInvitation::STATUS_COMPLETED => 'Completed',
            SurveyInvitation::STATUS_EXPIRED => 'Expired',
            SurveyInvitation::STATUS_FAILED => 'Failed',
            default => $latestSurvey instanceof GtsSurvey ? 'Completed' : 'No invitation',
        };
        $campaignStatus = $campaign?->getStatus();
        $campaignStatusLabel = $campaignStatus !== null ? ucfirst($campaignStatus) : 'No campaign';
        $campaignName = $campaign?->getName() ?: ($latestSurvey instanceof GtsSurvey ? 'Direct tracer response' : 'No tracer campaign yet');

        if ($currentInvitation instanceof SurveyInvitation && $invitationStatus === SurveyInvitation::STATUS_COMPLETED && $currentInvitation->getCompletedAt() instanceof \DateTimeInterface) {
            $timelineLabel = 'Completed At';
            $timelineValue = $currentInvitation->getCompletedAt()->format('M d, Y');
        } elseif ($currentInvitation instanceof SurveyInvitation && $invitationStatus === SurveyInvitation::STATUS_OPENED && $currentInvitation->getOpenedAt() instanceof \DateTimeInterface) {
            $timelineLabel = 'Opened At';
            $timelineValue = $currentInvitation->getOpenedAt()->format('M d, Y');
        } elseif ($currentInvitation instanceof SurveyInvitation && $currentInvitation->getSentAt() instanceof \DateTimeInterface) {
            $timelineLabel = 'Sent At';
            $timelineValue = $currentInvitation->getSentAt()->format('M d, Y');
        } elseif ($campaign !== null && $campaign->getScheduledSendAt() instanceof \DateTimeInterface) {
            $timelineLabel = 'Scheduled Send';
            $timelineValue = $campaign->getScheduledSendAt()->format('M d, Y');
        } elseif ($campaign !== null && $campaign->getSentAt() instanceof \DateTimeInterface) {
            $timelineLabel = 'Campaign Sent';
            $timelineValue = $campaign->getSentAt()->format('M d, Y');
        } else {
            $timelineLabel = $latestSurvey instanceof GtsSurvey ? 'Latest Response' : 'Tracer Timeline';
            $timelineValue = $latestSurvey instanceof GtsSurvey ? 'Response on file' : 'No tracer activity yet';
        }

        $windowValue = $currentInvitation instanceof SurveyInvitation && $currentInvitation->getExpiresAt() instanceof \DateTimeInterface
            ? ('Until ' . $currentInvitation->getExpiresAt()->format('M d, Y'))
            : ($campaign !== null ? ($campaign->getExpiryDays() . ' day window') : 'Not scheduled');

        $audienceParts = [];
        if ($campaign !== null && $campaign->getTargetBatchYear() !== null) {
            $audienceParts[] = 'Batch ' . $campaign->getTargetBatchYear();
        }
        if ($campaign !== null && $campaign->getTargetCourse() !== null) {
            $audienceParts[] = $campaign->getTargetCourse();
        }
        if ($campaign !== null && $campaign->getTargetCollege() !== null) {
            $audienceParts[] = $campaign->getTargetCollege();
        }

        $audienceSummary = $campaign !== null
            ? (count($audienceParts) > 0 ? implode(' • ', $audienceParts) : 'All eligible alumni')
            : ($latestSurvey instanceof GtsSurvey ? 'Direct tracer submission' : 'No audience configured');

        $statusSummary = match ($invitationStatus) {
            SurveyInvitation::STATUS_QUEUED => 'Your tracer invitation is queued under ' . $campaignName . '.',
            SurveyInvitation::STATUS_SENT => 'Your tracer invitation was sent under ' . $campaignName . ' and is waiting to be opened.',
            SurveyInvitation::STATUS_OPENED => 'You already opened ' . $campaignName . '; the response window is currently active.',
            SurveyInvitation::STATUS_COMPLETED => 'You completed ' . $campaignName . ' and the response remains on file.',
            SurveyInvitation::STATUS_EXPIRED => 'The latest invitation from ' . $campaignName . ' has expired.',
            SurveyInvitation::STATUS_FAILED => 'The latest invitation from ' . $campaignName . ' failed to send.',
            default => $latestSurvey instanceof GtsSurvey
                ? 'Your latest tracer response is already on file without a linked invitation campaign.'
                : 'No tracer invitation campaign is currently assigned to this account.',
        };

        [$entryUrl, $entryLabel] = match (true) {
            $currentInvitation instanceof SurveyInvitation && in_array($invitationStatus, [
                SurveyInvitation::STATUS_QUEUED,
                SurveyInvitation::STATUS_SENT,
                SurveyInvitation::STATUS_OPENED,
            ], true) => [
                $this->generateUrl('gts_invitation_entry', ['token' => $currentInvitation->getToken()]),
                $invitationStatus === SurveyInvitation::STATUS_OPENED ? 'Continue tracer form' : 'Open tracer form',
            ],
            !$latestSurvey instanceof GtsSurvey => [
                $this->generateUrl('gts_new'),
                'Start tracer form',
            ],
            default => [null, null],
        };

        return [
            'hasInvitation' => $currentInvitation instanceof SurveyInvitation,
            'hasCampaign' => $campaign !== null,
            'campaignName' => $campaignName,
            'campaignStatus' => $campaignStatus,
            'campaignStatusLabel' => $campaignStatusLabel,
            'invitationStatus' => $invitationStatus,
            'invitationStatusLabel' => $invitationStatusLabel,
            'statusSummary' => $statusSummary,
            'timelineLabel' => $timelineLabel,
            'timelineValue' => $timelineValue,
            'windowValue' => $windowValue,
            'audienceSummary' => $audienceSummary,
            'entryUrl' => $entryUrl,
            'entryLabel' => $entryLabel,
        ];
    }

    private function resolveTracerInvitationStatus(?SurveyInvitation $invitation, ?GtsSurvey $latestSurvey): ?string
    {
        if (!$invitation instanceof SurveyInvitation) {
            return null;
        }

        if ($invitation->getStatus() === SurveyInvitation::STATUS_COMPLETED) {
            return SurveyInvitation::STATUS_COMPLETED;
        }

        $latestSurveyInvitation = $latestSurvey?->getSurveyInvitation();
        if ($latestSurveyInvitation instanceof SurveyInvitation && $latestSurveyInvitation->getId() === $invitation->getId()) {
            return SurveyInvitation::STATUS_COMPLETED;
        }

        if ($invitation->isExpired() || $invitation->getStatus() === SurveyInvitation::STATUS_EXPIRED) {
            return SurveyInvitation::STATUS_EXPIRED;
        }

        return $invitation->getStatus();
    }

    /**
     * @param array{alumni: ?Alumni, milestoneAlumni: array<int, Alumni>, profileSnapshot: array{completionPercent: int, completionStatus: string, accountStatus: string}, latestSurvey?: ?object} $context
     *
     * @return array{
     *   featureKey: string,
     *   pageEyebrow: string,
     *   pageTitle: string,
     *   pageDescription: string,
     *   pagePills: array<int, string>,
     *   pageCards: array<int, array{eyebrow: string, title: string, body: string}>,
     *   primaryAction: array{href: string, label: string},
     *   secondaryAction: array{href: string, label: string},
     *   nextStep: array{title: string, body: string},
     *   pageModuleHint: string
     * }
     */
    private function buildAlumniProfileFeaturePageConfig(array $context): array
    {
        $alumni = $context['alumni'];
        $spotlightAlumnus = $context['milestoneAlumni'][0] ?? null;
        $hasLinkedAlumni = $alumni instanceof Alumni;

        return [
            'featureKey' => 'profile',
            'pageEyebrow' => 'PROFILE OVERVIEW',
            'pageTitle' => 'My Profile Overview',
            'pageDescription' => 'Review your account details, alumni record, and employment snapshot from a focused alumni page, then jump directly into profile editing when you need to update account fields.',
            'pagePills' => [
                $context['profileSnapshot']['completionPercent'] . '% complete',
                $context['profileSnapshot']['accountStatus'] . ' account',
                $hasLinkedAlumni ? 'Alumni record linked' : 'Alumni record not linked',
            ],
            'pageCards' => [],
            'primaryAction' => [
                'href' => $this->generateUrl('app_profile_edit'),
                'label' => 'Edit profile details',
            ],
            'secondaryAction' => [
                'href' => $this->generateUrl('app_alumni_feature_profile') . '#profile-record',
                'label' => 'Jump to record summary',
            ],
            'nextStep' => [
                'title' => $hasLinkedAlumni ? 'Edit your account and keep your alumni record in sync' : 'Update your account while your alumni record is being completed',
                'body' => $hasLinkedAlumni
                    ? 'Use the edit profile flow for account, photo, and password updates, then return here to review your linked alumni record and employment context.'
                    : 'Your account exists, but your alumni record still needs attention, so start by updating the profile details you can already manage.',
            ],
            'pageModuleHint' => $spotlightAlumnus
                ? ('Current milestone spotlight: ' . $spotlightAlumnus->getFullName() . '.')
                : ('Your profile status is currently ' . $context['profileSnapshot']['completionStatus'] . '.'),
        ];
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

    /**
     * @return array{
     *   completionPercent: int,
     *   completionStatus: string,
     *   accountStatus: string,
     *   tracerStatus: string,
     *   dateRegistered: \DateTimeInterface,
     *   lastLogin: ?\DateTimeInterface,
     *   profileCompletedAt: ?\DateTimeImmutable,
     *   lastTracerSubmissionAt: ?\DateTimeInterface
     * }
     */
    private function buildAlumniProfileSnapshot(User $user, ?Alumni $alumni, bool $hasGtsSurvey): array
    {
        $completionChecks = [
            $user->getEmail() !== null,
            $user->getSchoolId() !== null,
            $alumni !== null,
            $alumni?->getContactNumber() !== null,
            $alumni?->getHomeAddress() !== null,
            ($alumni?->getCourse() ?? $alumni?->getDegreeProgram()) !== null,
            $alumni?->getYearGraduated() !== null,
            ($alumni?->getEmploymentStatus() ?? $alumni?->getJobTitle() ?? $alumni?->getCompanyName()) !== null,
        ];

        $filledCount = count(array_filter($completionChecks, static fn (bool $isFilled): bool => $isFilled));
        $completionPercent = (int) round(($filledCount / count($completionChecks)) * 100);

        $completionStatus = match (true) {
            $completionPercent >= 85 => 'Complete',
            $completionPercent >= 50 => 'In Progress',
            default => 'Needs Attention',
        };

        return [
            'completionPercent' => $completionPercent,
            'completionStatus' => $completionStatus,
            'accountStatus' => ucfirst($user->getAccountStatus()),
            'tracerStatus' => $hasGtsSurvey ? ($alumni?->getTracerStatus() ?: 'Completed') : 'Pending',
            'dateRegistered' => $user->getDateRegistered(),
            'lastLogin' => $user->getLastLogin(),
            'profileCompletedAt' => $user->getProfileCompletedAt(),
            'lastTracerSubmissionAt' => $alumni?->getLastTracerSubmissionAt(),
        ];
    }
}
