<?php

namespace App\Tests\Controller;

use App\Entity\Alumni;
use App\Entity\Announcement;
use App\Entity\GtsSurvey;
use App\Entity\GtsSurveyTemplate;
use App\Entity\JobPosting;
use App\Entity\SurveyCampaign;
use App\Entity\SurveyInvitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class HomeLandingExperienceTest extends WebTestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = dirname(__DIR__, 2) . '/var/test_home_landing_' . uniqid('', true) . '.sqlite';
        $databaseUrl = 'sqlite:///' . str_replace('\\', '/', $this->databasePath);

        $_SERVER['DATABASE_URL'] = $databaseUrl;
        $_ENV['DATABASE_URL'] = $databaseUrl;

        self::ensureKernelShutdown();
        $entityManager = $this->bootEntityManager();
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        if ($metadata !== []) {
            (new SchemaTool($entityManager))->createSchema($metadata);
        }

        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();

        if (is_file($this->databasePath)) {
            @unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function testGuestHomeShowsPublicLandingNavigation(): void
    {
        $client = static::createClient();

        $client->request('GET', '/home');
        $content = $client->getResponse()->getContent() ?? '';

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav', 'Home');
        self::assertSelectorTextContains('nav', 'About');
        self::assertSelectorTextContains('nav', 'Contact');
        self::assertSelectorTextContains('nav', 'FAQ');
        self::assertSelectorTextContains('nav', 'Login');
        self::assertSelectorTextContains('nav', 'Sign Up');
        self::assertSelectorTextContains('body', 'Join Now');
        self::assertSelectorTextNotContains('nav', 'Logout');
        self::assertStringContainsString('girl.png', $content);
    }

    public function testAlumniHomeShowsLandingStyleFeatureNavigation(): void
    {
        $entityManager = $this->bootEntityManager();
        $alumniUser = $this->createActiveAlumniUser('landing-alumni@example.com', '2022-10001');
        $entityManager->persist($alumniUser);
        $entityManager->persist($alumniUser->getAlumni());
        $entityManager->flush();
        $userId = $alumniUser->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $user = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($userId);
        $client->loginUser($user);

        $client->request('GET', '/home');
        $content = $client->getResponse()->getContent() ?? '';

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav', 'Alumni Home');
        self::assertSelectorTextContains('nav', 'Announcements');
        self::assertSelectorTextContains('nav', 'Jobs');
        self::assertSelectorTextContains('nav', 'Tracer Survey');
        self::assertSelectorTextContains('nav', 'My Profile');
        self::assertSelectorTextContains('nav', 'Logout');
        self::assertSelectorExists('nav a[href="/alumni-home/announcements"]');
        self::assertSelectorExists('nav a[href="/alumni-home/jobs"]');
        self::assertSelectorExists('nav a[href="/alumni-home/tracer-survey"]');
        self::assertSelectorExists('nav a[href="/alumni-home/my-profile"]');
        self::assertSelectorExists('.landing-alumni-nav-links a[href="/logout"]');
        self::assertSelectorTextContains('body', 'Welcome back, Alice');
        self::assertSelectorTextContains('body', 'Open tracer page');
        self::assertSelectorTextContains('body', 'Open profile page');
        self::assertSelectorTextContains('body', 'Tracer pending');
        self::assertSelectorTextNotContains('nav', 'Sign Up');
        self::assertStringNotContainsString('girl.png', $content);
        self::assertStringNotContainsString('teacher-explaining.png', $content);
        self::assertSelectorCount(8, '[data-alumni-dashboard-card]');
        self::assertSelectorCount(8, '[data-alumni-dashboard-link]');
        self::assertSelectorCount(0, 'a[data-alumni-dashboard-card]');
        self::assertSelectorExists('[data-alumni-home-hero-preview="announcements"]');
        self::assertSelectorExists('[data-alumni-home-hero-preview="jobs"]');
        self::assertSelectorExists('[data-alumni-home-hero-preview="tracer"]');
        self::assertSelectorExists('[data-alumni-home-hero-preview="profile"]');
        self::assertSelectorExists('[data-alumni-dashboard-link][href="/alumni-home/announcements"]');
        self::assertSelectorExists('[data-alumni-dashboard-link][href="/alumni-home/jobs"]');
        self::assertSelectorExists('[data-alumni-dashboard-link][href="/alumni-home/tracer-survey"]');
        self::assertSelectorExists('[data-alumni-dashboard-link][href="/alumni-home/my-profile"]');
        $this->assertLandingStaysOutOfDashboardModules($content);
    }

    public function testAlumniFeaturePagesRenderInsideLandingExperience(): void
    {
        $entityManager = $this->bootEntityManager();
        $alumniUser = $this->createActiveAlumniUser('landing-feature-pages@example.com', '2022-10003');
        $announcement = (new Announcement())
            ->setTitle('Alumni Homecoming Weekend')
            ->setDescription('Join the alumni office for the upcoming homecoming weekend, campus fellowship activities, and department reunions.')
            ->setCategory('Events')
            ->setPostedBy($alumniUser)
            ->setIsActive(true);
        $job = (new JobPosting())
            ->setTitle('Junior Data Analyst')
            ->setCompanyName('NORSU Career Partners')
            ->setLocation('Dumaguete City')
            ->setDescription('Support reporting dashboards, prepare hiring insights, and collaborate with partner employers for alumni placements.')
            ->setEmploymentType('Full-time')
            ->setRelatedCourse('BSIT')
            ->setSalaryRange('PHP 25,000 - PHP 30,000')
            ->setIndustry('Education Technology')
            ->setDeadline(new \DateTime('+30 days'))
            ->setIsActive(true);
        $survey = (new GtsSurvey())
            ->setUser($alumniUser)
            ->setName('Alice Alumni')
            ->setEmailAddress('landing-feature-pages@example.com')
            ->setPresentlyEmployed('Yes')
            ->setPresentEmploymentStatus('Regular or Permanent')
            ->setPresentOccupation('Data Analyst')
            ->setCompanyNameAddress('NORSU Career Partners, Dumaguete City')
            ->setPlaceOfWork('Local');
        $surveyTemplate = $this->createSurveyTemplate('Batch 2022 Graduate Tracer');
        $campaign = $this->createTracerCampaign($surveyTemplate, 'Batch 2022 Graduate Tracer');
        $invitation = $this->createTracerInvitation(
            $campaign,
            $alumniUser,
            SurveyInvitation::STATUS_COMPLETED,
            new \DateTimeImmutable('-5 days'),
            new \DateTimeImmutable('-4 days'),
            new \DateTimeImmutable('-3 days'),
            new \DateTimeImmutable('+9 days')
        );
        $survey
            ->setSurveyTemplate($surveyTemplate)
            ->setSurveyInvitation($invitation);

        $alumniUser->getAlumni()
            ->setContactNumber('09171234567')
            ->setHomeAddress('Dumaguete City, Negros Oriental')
            ->setCourse('BSIT')
            ->setCollege('College of Arts and Sciences')
            ->setEmploymentStatus('Employed')
            ->setCompanyName('NORSU Career Partners')
            ->setJobTitle('Data Analyst')
            ->setIndustry('Education Technology')
            ->setTracerStatus('Completed')
            ->setLastTracerSubmissionAt(new \DateTime('-3 days'))
            ->setCareerAchievements('Promoted to analytics lead for alumni reporting initiatives.');

        $entityManager->persist($alumniUser);
        $entityManager->persist($alumniUser->getAlumni());
        $entityManager->persist($surveyTemplate);
        $entityManager->persist($campaign);
        $entityManager->persist($invitation);
        $entityManager->persist($announcement);
        $entityManager->persist($job);
        $entityManager->persist($survey);
        $entityManager->flush();
        $userId = $alumniUser->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $user = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($userId);
        $client->loginUser($user);

        $client->request('GET', '/home');
        $homeContent = $client->getResponse()->getContent() ?? '';

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-alumni-home-hero-preview="announcements"]');
        self::assertSelectorTextContains('[data-alumni-home-hero-preview="announcements"]', 'Alumni Homecoming Weekend');
        self::assertSelectorTextContains('[data-alumni-home-summary="announcements"]', 'Events');
        self::assertSelectorExists('[data-alumni-home-hero-preview="jobs"]');
        self::assertSelectorTextContains('[data-alumni-home-hero-preview="jobs"]', 'Junior Data Analyst');
        self::assertSelectorTextContains('[data-alumni-home-summary="jobs"]', 'NORSU Career Partners');
        self::assertSelectorExists('[data-alumni-home-hero-preview="tracer"]');
        self::assertSelectorTextContains('[data-alumni-home-hero-preview="tracer"]', 'Completed invitation');
        self::assertSelectorTextContains('[data-alumni-home-hero-preview="tracer"]', 'Batch 2022 Graduate Tracer');
        self::assertSelectorTextContains('[data-alumni-home-summary="tracer"]', 'Completed');
        self::assertSelectorTextContains('[data-alumni-home-summary="tracer"]', 'Sent campaign');
        self::assertSelectorExists('[data-alumni-home-hero-preview="profile"]');
        self::assertSelectorTextContains('[data-alumni-home-hero-preview="profile"]', 'Linked alumni record ready');
        self::assertSelectorTextContains('[data-alumni-home-launcher="profile"]', 'BSIT');
        self::assertSelectorTextContains('[data-alumni-home-summary="profile"]', 'Active account');
        self::assertSelectorTextContains('[data-alumni-home-launcher="announcements"]', 'Latest Notice Snapshot');
        self::assertSelectorTextContains('[data-alumni-home-launcher="jobs"]', 'Opportunity Snapshot');
        self::assertSelectorTextContains('[data-alumni-home-launcher="tracer"]', 'Tracer Snapshot');
        self::assertSelectorTextContains('[data-alumni-home-launcher="tracer"]', 'Completed invitation');
        self::assertSelectorTextContains('[data-alumni-home-launcher="profile"]', 'Identity Snapshot');
        $this->assertLandingStaysOutOfDashboardModules($homeContent);

        foreach ([
            '/alumni-home/announcements' => 'Announcements and Updates',
            '/alumni-home/jobs' => 'Jobs and Career Opportunities',
            '/alumni-home/tracer-survey' => 'Tracer Survey Status',
            '/alumni-home/my-profile' => 'My Profile Overview',
        ] as $uri => $heading) {
            $client->request('GET', $uri);
            $content = $client->getResponse()->getContent() ?? '';

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('nav', 'Alumni Home');
            self::assertSelectorTextContains('body', $heading);

            if ($uri === '/alumni-home/announcements') {
                self::assertSelectorExists('[data-alumni-feature-hero="announcements"]');
                self::assertSelectorTextContains('body', 'Latest Notice Snapshot');
                self::assertSelectorTextContains('body', 'Jump to notice board');
                self::assertSelectorTextContains('body', 'Go to jobs page');
                self::assertSelectorCount(1, '[data-alumni-feature-announcement-card]');
                self::assertSelectorTextContains('body', 'Alumni Homecoming Weekend');
                self::assertSelectorTextContains('body', 'Events');
                self::assertSelectorTextContains('body', 'Alice Alumni');
                self::assertSelectorCount(0, '[data-alumni-feature-announcement-card] a');
            }

            if ($uri === '/alumni-home/jobs') {
                self::assertSelectorExists('[data-alumni-feature-hero="jobs"]');
                self::assertSelectorTextContains('body', 'Opportunity Snapshot');
                self::assertSelectorTextContains('body', 'Jump to opportunity board');
                self::assertSelectorCount(1, '[data-alumni-feature-job-card]');
                self::assertSelectorTextContains('body', 'Junior Data Analyst');
                self::assertSelectorTextContains('body', 'NORSU Career Partners');
                self::assertSelectorTextContains('body', 'PHP 25,000 - PHP 30,000');
                self::assertSelectorCount(0, '[data-alumni-feature-job-card] a');
            }

            if ($uri === '/alumni-home/tracer-survey') {
                self::assertSelectorExists('[data-alumni-feature-hero="tracer"]');
                self::assertSelectorTextContains('body', 'Tracer Snapshot');
                self::assertSelectorTextContains('body', 'Review tracer summary');
                self::assertSelectorTextContains('body', 'Go to profile page');
                self::assertSelectorExists('[data-alumni-feature-tracer-summary]');
                self::assertSelectorTextContains('body', 'Tracer response saved');
                self::assertSelectorTextContains('body', 'Invitation Status');
                self::assertSelectorTextContains('body', 'Campaign Status');
                self::assertSelectorTextContains('body', 'Batch 2022 Graduate Tracer');
                self::assertSelectorTextContains('body', 'Completed');
                self::assertSelectorTextContains('body', 'Sent');
                self::assertSelectorTextContains('body', 'Regular or Permanent');
                self::assertSelectorTextContains('body', 'Data Analyst');
                self::assertSelectorTextContains('body', 'NORSU Career Partners, Dumaguete City');
                self::assertSelectorCount(0, '[data-alumni-feature-tracer-summary] a');
            }

            if ($uri === '/alumni-home/my-profile') {
                self::assertSelectorExists('[data-alumni-feature-hero="profile"]');
                self::assertSelectorTextContains('body', 'Identity Snapshot');
                self::assertSelectorTextContains('body', 'Edit profile details');
                self::assertSelectorTextContains('body', 'Jump to record summary');
                self::assertSelectorExists('[data-alumni-feature-profile-card]');
                self::assertSelectorExists('[data-alumni-profile-edit-link][href="/profile/edit"]');
                self::assertSelectorTextContains('body', 'Alice Alumni');
                self::assertSelectorTextContains('body', 'BSIT');
                self::assertSelectorTextContains('body', 'College of Arts and Sciences');
                self::assertSelectorTextContains('body', 'Promoted to analytics lead for alumni reporting initiatives.');
                self::assertSelectorTextContains('body', 'Completed');
            }

            $this->assertLandingStaysOutOfDashboardModules($content);
        }
    }

    public function testAuthenticatedAlumniRequestingLoginRedirectsToHome(): void
    {
        $entityManager = $this->bootEntityManager();
        $alumniUser = $this->createActiveAlumniUser('landing-login@example.com', '2022-10002');
        $entityManager->persist($alumniUser);
        $entityManager->persist($alumniUser->getAlumni());
        $entityManager->flush();
        $userId = $alumniUser->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $user = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($userId);
        $client->loginUser($user);

        $client->request('GET', '/login');

        self::assertResponseRedirects('/home');
    }

    public function testAlumniProfileRoutesStayInsideLandingExperienceAndUpdateAccount(): void
    {
        $entityManager = $this->bootEntityManager();
        $alumniUser = $this->createActiveAlumniUser('landing-profile-edit@example.com', '2022-10006');
        $entityManager->persist($alumniUser);
        $entityManager->persist($alumniUser->getAlumni());
        $entityManager->flush();
        $userId = $alumniUser->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $user = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($userId);
        $client->loginUser($user);

        $client->request('GET', '/profile');

        self::assertResponseRedirects('/alumni-home/my-profile');

        $crawler = $client->request('GET', '/profile/edit');
        $content = $client->getResponse()->getContent() ?? '';

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav', 'Alumni Home');
        self::assertSelectorTextContains('body', 'Edit My Profile');
        self::assertSelectorTextContains('body', 'Account Details');
        self::assertSelectorExists('[data-alumni-profile-edit-form]');
        self::assertSelectorCount(0, 'aside#sidebar');
        self::assertSelectorCount(0, '#sidebarToggle');
        self::assertStringNotContainsString('Job Announcements', $content);
        self::assertStringNotContainsString('Submit Feedback', $content);
        self::assertStringNotContainsString('GTS Survey', $content);

        $form = $crawler->selectButton('Save Changes')->form([
            'firstName' => 'Alice Updated',
            'lastName' => 'Alumni',
            'email' => 'alice.updated@example.com',
            'currentPassword' => '',
            'newPassword' => '',
            'confirmPassword' => '',
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/alumni-home/my-profile');

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Alice Updated');
        self::assertSelectorTextContains('body', 'alice.updated@example.com');
    }

    public function testAlumniTracerLandingReflectsOpenedInvitationCampaignState(): void
    {
        $entityManager = $this->bootEntityManager();
        $alumniUser = $this->createActiveAlumniUser('landing-tracer-opened@example.com', '2022-10004');
        $surveyTemplate = $this->createSurveyTemplate('Opened Invitation Tracer');
        $campaign = $this->createTracerCampaign($surveyTemplate, 'Opened Invitation Tracer');
        $invitation = $this->createTracerInvitation(
            $campaign,
            $alumniUser,
            SurveyInvitation::STATUS_OPENED,
            new \DateTimeImmutable('-2 days'),
            new \DateTimeImmutable('-1 day'),
            null,
            new \DateTimeImmutable('+7 days')
        );

        $entityManager->persist($alumniUser);
        $entityManager->persist($alumniUser->getAlumni());
        $entityManager->persist($surveyTemplate);
        $entityManager->persist($campaign);
        $entityManager->persist($invitation);
        $entityManager->flush();
        $userId = $alumniUser->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $user = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($userId);
        $client->loginUser($user);

        $client->request('GET', '/home');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-alumni-home-hero-preview="tracer"]', 'Opened invitation');
        self::assertSelectorTextContains('[data-alumni-home-hero-preview="tracer"]', 'Opened Invitation Tracer');
        self::assertSelectorTextContains('[data-alumni-home-summary="tracer"]', 'Opened');
        self::assertSelectorTextContains('[data-alumni-home-summary="tracer"]', 'Sent campaign');

        $client->request('GET', '/alumni-home/tracer-survey');
        $content = $client->getResponse()->getContent() ?? '';

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Opened Invitation Tracer');
        self::assertSelectorTextContains('body', 'Opened');
        self::assertSelectorTextContains('body', 'Sent');
        self::assertSelectorTextContains('body', 'Continue tracer form');
        self::assertStringContainsString('/gts/invitations/', $content);
        self::assertSelectorTextContains('body', 'Until');
        $this->assertLandingStaysOutOfDashboardModules($content);
    }

    public function testAlumniTracerFormRendersInsideLandingExperience(): void
    {
        $entityManager = $this->bootEntityManager();
        $alumniUser = $this->createActiveAlumniUser('landing-tracer-form@example.com', '2022-10007');
        $entityManager->persist($alumniUser);
        $entityManager->persist($alumniUser->getAlumni());
        $entityManager->flush();
        $userId = $alumniUser->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $user = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($userId);
        $client->loginUser($user);

        $client->request('GET', '/gts/new');
        $content = $client->getResponse()->getContent() ?? '';

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav', 'Alumni Home');
        self::assertSelectorTextContains('body', 'Graduate Tracer Survey');
        self::assertSelectorExists('[data-alumni-tracer-form-shell]');
        self::assertSelectorExists('form#gtsForm');
        self::assertSelectorCount(0, 'aside#sidebar');
        self::assertSelectorCount(0, '#sidebarToggle');
        self::assertStringNotContainsString('Job Announcements', $content);
        self::assertStringNotContainsString('Submit Feedback', $content);
    }

    public function testAlumniTracerLandingReflectsExpiredInvitationCampaignState(): void
    {
        $entityManager = $this->bootEntityManager();
        $alumniUser = $this->createActiveAlumniUser('landing-tracer-expired@example.com', '2022-10005');
        $surveyTemplate = $this->createSurveyTemplate('Expired Invitation Tracer');
        $campaign = $this->createTracerCampaign($surveyTemplate, 'Expired Invitation Tracer');
        $invitation = $this->createTracerInvitation(
            $campaign,
            $alumniUser,
            SurveyInvitation::STATUS_SENT,
            new \DateTimeImmutable('-10 days'),
            null,
            null,
            new \DateTimeImmutable('-1 day')
        );

        $entityManager->persist($alumniUser);
        $entityManager->persist($alumniUser->getAlumni());
        $entityManager->persist($surveyTemplate);
        $entityManager->persist($campaign);
        $entityManager->persist($invitation);
        $entityManager->flush();
        $userId = $alumniUser->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $user = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($userId);
        $client->loginUser($user);

        $client->request('GET', '/home');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-alumni-home-hero-preview="tracer"]', 'Expired invitation');
        self::assertSelectorTextContains('[data-alumni-home-hero-preview="tracer"]', 'Expired Invitation Tracer');
        self::assertSelectorTextContains('[data-alumni-home-summary="tracer"]', 'Expired');

        $client->request('GET', '/alumni-home/tracer-survey');
        $content = $client->getResponse()->getContent() ?? '';

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Expired Invitation Tracer');
        self::assertSelectorTextContains('body', 'Expired');
        self::assertSelectorTextContains('body', 'Sent');
        $this->assertLandingStaysOutOfDashboardModules($content);
    }

    private function createActiveAlumniUser(string $email, string $studentNumber): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Alice')
            ->setLastName('Alumni')
            ->setRoles([User::ROLE_ALUMNI])
            ->setPassword('Password1!')
            ->setAccountStatus('active')
            ->setSchoolId($studentNumber);

        $alumni = (new Alumni())
            ->setStudentNumber($studentNumber)
            ->setFirstName('Alice')
            ->setLastName('Alumni')
            ->setEmailAddress($email)
            ->setYearGraduated(2022)
            ->setUser($user);

        $user->setAlumni($alumni);

        return $user;
    }

    private function createSurveyTemplate(string $title): GtsSurveyTemplate
    {
        return (new GtsSurveyTemplate())
            ->setTitle($title)
            ->setDescription('Landing test tracer template')
            ->setIsActive(true);
    }

    private function createTracerCampaign(GtsSurveyTemplate $surveyTemplate, string $name, string $status = 'sent'): SurveyCampaign
    {
        return (new SurveyCampaign())
            ->setSurveyTemplate($surveyTemplate)
            ->setName($name)
            ->setEmailSubject($name . ' invitation')
            ->setEmailBody('Tracer invitation body')
            ->setTargetGraduationYears(['2022'])
            ->setExpiryDays(14)
            ->setStatus($status)
            ->setSentAt(new \DateTimeImmutable('-2 days'));
    }

    private function createTracerInvitation(
        SurveyCampaign $campaign,
        User $user,
        string $status,
        ?\DateTimeImmutable $sentAt,
        ?\DateTimeImmutable $openedAt,
        ?\DateTimeImmutable $completedAt,
        ?\DateTimeImmutable $expiresAt
    ): SurveyInvitation {
        return (new SurveyInvitation())
            ->setCampaign($campaign)
            ->setUser($user)
            ->setStatus($status)
            ->setSentAt($sentAt)
            ->setOpenedAt($openedAt)
            ->setCompletedAt($completedAt)
            ->setExpiresAt($expiresAt);
    }

    private function bootEntityManager(): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        return static::getContainer()->get(ManagerRegistry::class)->getManager();
    }

    private function assertLandingStaysOutOfDashboardModules(string $content): void
    {
        $router = static::getContainer()->get(UrlGeneratorInterface::class);

        foreach (['announcement_index', 'job_board_index', 'app_profile'] as $routeName) {
            $dashboardUrl = $router->generate($routeName);
            self::assertStringNotContainsString('href="' . $dashboardUrl . '"', $content);
        }
    }
}