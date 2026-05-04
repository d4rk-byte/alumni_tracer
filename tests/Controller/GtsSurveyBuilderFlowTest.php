<?php

namespace App\Tests\Controller;

use App\Entity\Alumni;
use App\Entity\GtsSurvey;
use App\Entity\GtsSurveyQuestion;
use App\Entity\GtsSurveyTemplate;
use App\Entity\Notification;
use App\Entity\SurveyCampaign;
use App\Entity\SurveyInvitation;
use App\Entity\User;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Service\SurveyCampaignDispatchService;

class GtsSurveyBuilderFlowTest extends WebTestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = dirname(__DIR__, 2) . '/var/test_gts_builder_' . uniqid('', true) . '.sqlite';
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

    public function testSurveyPageRendersConfiguredBuilderQuestions(): void
    {
        $entityManager = $this->bootEntityManager();
        $alumniUser = $this->createActiveAlumniUser('builder-render@example.com', '2022-0001', '2022-render');
        $entityManager->persist($alumniUser);
        $entityManager->persist($alumniUser->getAlumni());
        $template = (new GtsSurveyTemplate())
            ->setTitle('Invitation Template')
            ->setDescription('Template-scoped question set')
            ->setIsActive(true);
        $entityManager->persist($template);
        $entityManager->persist(
            $this->createQuestion('Custom Section', 'Custom builder question', 'text', 10)
        );
        $entityManager->persist(
            $this->createQuestion('Template Section', 'Template-scoped question', 'text', 20)
                ->setSurveyTemplate($template)
        );
        $entityManager->flush();
        $userId = $alumniUser->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $user = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($userId);
        $client->loginUser($user);

        $client->request('GET', '/gts/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Custom builder question');
        self::assertSelectorTextNotContains('body', 'Template-scoped question');
        self::assertSelectorTextNotContains('body', '2. Permanent Address');
    }

    public function testSubmittingSurveyStoresSnapshotAndUpdatesTracerStatus(): void
    {
        $entityManager = $this->bootEntityManager();
        $alumniUser = $this->createActiveAlumniUser('builder-submit@example.com', '2022-0002', '2022-submit');
        $entityManager->persist($alumniUser);
        $entityManager->persist($alumniUser->getAlumni());

        $phoneQuestion = $this->createQuestion('A. General Information', '4. Telephone / Contact Number(s)', 'text', 10);
        $examQuestion = $this->createQuestion('B. Educational Background', '13. Professional Examination(s) Passed', 'repeater', 20, [
            ['key' => 'name', 'label' => 'Name of Examination', 'type' => 'text'],
            ['key' => 'dateTaken', 'label' => 'Date Taken', 'type' => 'date'],
            ['key' => 'rating', 'label' => 'Rating', 'type' => 'select', 'options' => ['Passed', 'Failed', 'Pending']],
        ]);

        $entityManager->persist($phoneQuestion);
        $entityManager->persist($examQuestion);
        $entityManager->flush();

        $userId = $alumniUser->getId();
        $alumniId = $alumniUser->getAlumni()->getId();
        $phoneQuestionId = $phoneQuestion->getId();
        $examQuestionId = $examQuestion->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $user = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($userId);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/gts/new');
        $token = $crawler->filter('input[name="gts_survey[_token]"]')->attr('value');

        $client->request('POST', '/gts/new', [
            'gts_survey' => [
                '_token' => $token,
            ],
            'dynamic_answers' => [
                (string) $phoneQuestionId => '555-0101',
                (string) $examQuestionId => [
                    [
                        'name' => 'LET',
                        'dateTaken' => '2024-04-20',
                        'rating' => 'Passed',
                    ],
                ],
            ],
        ]);

        self::assertResponseRedirects('/profile');

        $entityManager = $this->bootEntityManager();
        $owner = $entityManager->getRepository(User::class)->find($userId);
        $survey = $entityManager->getRepository(GtsSurvey::class)->findOneBy(['user' => $owner]);
        $alumni = $entityManager->getRepository(Alumni::class)->find($alumniId);

        self::assertNotNull($survey);
        self::assertNotNull($alumni);
        self::assertSame('TRACED', $alumni->getTracerStatus());

        $responses = $survey->getDynamicAnswers()['responses'] ?? [];
        self::assertCount(2, $responses);
        self::assertSame('4. Telephone / Contact Number(s)', $responses[0]['questionText']);
        self::assertSame('555-0101', $responses[0]['answer']);
        self::assertSame('13. Professional Examination(s) Passed', $responses[1]['questionText']);
        self::assertSame('LET', $responses[1]['answer'][0]['name']);
        self::assertSame('Passed', $responses[1]['answer'][0]['rating']);
    }

    public function testResponseIndexUsesDynamicSnapshotSummaryFields(): void
    {
        $entityManager = $this->bootEntityManager();
        $staff = $this->createStaffUser('gts-staff@example.com');
        $owner = $this->createActiveAlumniUser('builder-index@example.com', '2022-0003', '2022-index');
        $survey = (new GtsSurvey())
            ->setUser($owner)
            ->setName('Index Survey, Owner')
            ->setEmailAddress('builder-index@example.com')
            ->setDynamicAnswers([
                'version' => 2,
                'responses' => [
                    [
                        'key' => '4',
                        'section' => 'A. General Information',
                        'questionText' => '4. Telephone / Contact Number(s)',
                        'inputType' => 'text',
                        'numberKey' => '4',
                        'answer' => '632-0000',
                    ],
                    [
                        'key' => '5',
                        'section' => 'A. General Information',
                        'questionText' => '5. Mobile Number',
                        'inputType' => 'text',
                        'numberKey' => '5',
                        'answer' => '0917-000-0000',
                    ],
                    [
                        'key' => '19',
                        'section' => 'D. Employment Data',
                        'questionText' => '19. Present Occupation (PSOC Classification)',
                        'inputType' => 'text',
                        'numberKey' => '19',
                        'answer' => 'Professionals',
                    ],
                    [
                        'key' => '20a',
                        'section' => 'D. Employment Data',
                        'questionText' => '20a. Name of Company / Organization (including address)',
                        'inputType' => 'textarea',
                        'numberKey' => '20a',
                        'answer' => "Acme Corporation\nDumaguete City",
                    ],
                ],
            ]);

        $entityManager->persist($staff);
        $entityManager->persist($owner);
        $entityManager->persist($owner->getAlumni());
        $entityManager->persist($survey);
        $entityManager->flush();
        $staffId = $staff->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $staff = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($staffId);
        $client->loginUser($staff);

        $client->request('GET', '/gts/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '632-0000 / 0917-000-0000');
        self::assertSelectorTextContains('body', 'Professionals');
        self::assertSelectorTextContains('body', 'Acme Corporation');
        self::assertSelectorTextContains('body', 'Dumaguete City');
    }

    public function testAdminResponsesApiFallsBackToAccountInvitationAndBatch(): void
    {
        $entityManager = $this->bootEntityManager();
        $admin = $this->createAdminUser('gts-admin@example.com');
        $owner = $this->createActiveAlumniUser('builder-api@example.com', '2022-0005', '2022-api');
        $template = (new GtsSurveyTemplate())
            ->setTitle('Fallback Template')
            ->setDescription('Template for fallback metadata')
            ->setIsActive(true);
        $campaign = (new SurveyCampaign())
            ->setSurveyTemplate($template)
            ->setName('Batch 2022 Campaign')
            ->setEmailSubject('Tracer invitation')
            ->setEmailBody('Please answer the tracer survey.')
            ->setTargetBatchYear(2022)
            ->setStatus('sent')
            ->setSentAt(new \DateTimeImmutable());
        $invitation = (new SurveyInvitation())
            ->setCampaign($campaign)
            ->setUser($owner)
            ->setStatus(SurveyInvitation::STATUS_SENT)
            ->setSentAt(new \DateTimeImmutable());
        $survey = (new GtsSurvey())
            ->setUser($owner)
            ->setSurveyTemplate($template)
            ->setName('Api Survey, Owner')
            ->setEmailAddress('builder-api@example.com')
            ->setDynamicAnswers([]);

        $entityManager->persist($admin);
        $entityManager->persist($owner);
        $entityManager->persist($owner->getAlumni());
        $entityManager->persist($template);
        $entityManager->persist($campaign);
        $entityManager->persist($invitation);
        $entityManager->persist($survey);
        $entityManager->flush();
        $adminId = $admin->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $admin = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($adminId);
        $client->loginUser($admin);

        $client->request('GET', '/api/admin/gts/responses?limit=100');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);

        $item = $payload['items'][0] ?? null;
        self::assertIsArray($item);
        self::assertSame('Batch 2022 Campaign', $item['campaign']['name'] ?? null);
        self::assertSame(2022, $item['targetBatchYear'] ?? null);
        self::assertSame(SurveyInvitation::STATUS_SENT, $item['invitation']['status'] ?? null);
    }

    public function testCampaignDispatchCreatesInvitationsOnlyForSelectedBatch(): void
    {
        $entityManager = $this->bootEntityManager();
        $selectedBatchUser = $this->createActiveAlumniUser('batch-2027@example.com', '2027-0001', '2027-student', 2027);
        $otherBatchUser = $this->createActiveAlumniUser('batch-2026@example.com', '2026-0001', '2026-student', 2026);
        $template = (new GtsSurveyTemplate())
            ->setTitle('Batch Target Template')
            ->setDescription('Template for batch targeting')
            ->setIsActive(true);
        $campaign = (new SurveyCampaign())
            ->setSurveyTemplate($template)
            ->setName('Batch 2027 Campaign')
            ->setEmailSubject('Tracer invitation')
            ->setEmailBody('Please answer the tracer survey.')
            ->setTargetBatchYear(2027)
            ->setExpiryDays(30);

        $entityManager->persist($selectedBatchUser);
        $entityManager->persist($selectedBatchUser->getAlumni());
        $entityManager->persist($otherBatchUser);
        $entityManager->persist($otherBatchUser->getAlumni());
        $entityManager->persist($template);
        $entityManager->persist($campaign);
        $entityManager->flush();

        $dispatchService = new SurveyCampaignDispatchService(
            $entityManager->getRepository(Alumni::class),
            $entityManager,
            new class implements MessageBusInterface {
                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    return new Envelope($message, $stamps);
                }
            },
        );

        $queuedCount = $dispatchService->dispatchCampaign($campaign, 'http://localhost:3000');

        self::assertSame(1, $queuedCount);

        $invitations = $entityManager->getRepository(SurveyInvitation::class)->findBy(['campaign' => $campaign]);
        self::assertCount(1, $invitations);
        self::assertSame($selectedBatchUser->getId(), $invitations[0]->getUser()->getId());
        self::assertSame(2027, $invitations[0]->getUser()->getAlumni()?->getYearGraduated());
    }

    public function testCampaignDispatchRejectsCampaignWithoutTargetBatch(): void
    {
        $entityManager = $this->bootEntityManager();
        $template = (new GtsSurveyTemplate())
            ->setTitle('Missing Batch Template')
            ->setDescription('Template for missing batch guard')
            ->setIsActive(true);
        $campaign = (new SurveyCampaign())
            ->setSurveyTemplate($template)
            ->setName('Missing Batch Campaign')
            ->setEmailSubject('Tracer invitation')
            ->setEmailBody('Please answer the tracer survey.');

        $dispatchService = new SurveyCampaignDispatchService(
            $entityManager->getRepository(Alumni::class),
            $entityManager,
            new class implements MessageBusInterface {
                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    return new Envelope($message, $stamps);
                }
            },
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Survey campaigns must target a batch year before dispatch.');

        $dispatchService->dispatchCampaign($campaign, 'http://localhost:3000');
    }

    public function testDashboardAnalyticsReadsSubmittedSurveyFormAnswers(): void
    {
        $entityManager = $this->bootEntityManager();
        $admin = $this->createAdminUser('survey-analytics-admin@example.com');
        $owner = $this->createActiveAlumniUser('survey-analytics@example.com', '2027-0002', '2027-analytics', 2027);
        $survey = (new GtsSurvey())
            ->setUser($owner)
            ->setName('Analytics Survey, Owner')
            ->setEmailAddress('survey-analytics@example.com')
            ->setDynamicAnswers([
                'version' => 2,
                'responses' => [
                    [
                        'key' => 'q16',
                        'section' => 'D. Employment Data',
                        'questionText' => '16. Are you presently employed?',
                        'inputType' => 'radio',
                        'numberKey' => '16',
                        'answer' => 'Yes',
                    ],
                    [
                        'key' => 'q18',
                        'section' => 'D. Employment Data',
                        'questionText' => '18. Present Employment Status',
                        'inputType' => 'select',
                        'numberKey' => '18',
                        'answer' => 'Regular or Permanent',
                    ],
                    [
                        'key' => 'q21',
                        'section' => 'D. Employment Data',
                        'questionText' => '21. Place of Work',
                        'inputType' => 'radio',
                        'numberKey' => '21',
                        'answer' => 'Local',
                    ],
                    [
                        'key' => 'q24',
                        'section' => 'D. Employment Data',
                        'questionText' => '24. Is your first job related to the course you took up in college?',
                        'inputType' => 'radio',
                        'numberKey' => '24',
                        'answer' => 'Yes',
                    ],
                    [
                        'key' => 'q31',
                        'section' => 'D. Employment Data',
                        'questionText' => '31. Initial Gross Monthly Earning in First Job',
                        'inputType' => 'select',
                        'numberKey' => '31',
                        'answer' => 'PHP 25,000 and above',
                    ],
                    [
                        'key' => 'q33',
                        'section' => 'D. Employment Data',
                        'questionText' => '33. Competencies learned in college useful in first job',
                        'inputType' => 'checkbox',
                        'numberKey' => '33',
                        'answer' => ['Communication skills', 'Problem-solving skills'],
                    ],
                ],
            ]);

        $entityManager->persist($admin);
        $entityManager->persist($owner);
        $entityManager->persist($owner->getAlumni());
        $entityManager->persist($survey);
        $entityManager->flush();
        $adminId = $admin->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $admin = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($adminId);
        $client->loginUser($admin);

        $client->request('GET', '/api/admin/dashboard');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);

        $analytics = $payload['surveyAnalytics'] ?? null;
        self::assertIsArray($analytics);
        self::assertSame(1, $analytics['responseCount'] ?? null);
        self::assertEquals(100.0, $analytics['employmentRate'] ?? null);
        self::assertEquals(100.0, $analytics['courseAlignmentRate'] ?? null);
        self::assertSame('Regular or Permanent', $analytics['presentEmploymentStatus'][0]['label'] ?? null);
        self::assertSame('PHP 25,000 and above', $analytics['salaryRanges'][0]['label'] ?? null);
        self::assertContains('Batch 2027', array_column($analytics['responsesByBatch'] ?? [], 'label'));
        self::assertContains('Communication skills', array_column($analytics['competencies'] ?? [], 'label'));
    }

    public function testAdminUsersExposeLatestSurveyEmploymentForAlumniRecords(): void
    {
        $entityManager = $this->bootEntityManager();
        $admin = $this->createAdminUser('alumni-records-admin@example.com');
        $owner = $this->createActiveAlumniUser('alumni-records-survey@example.com', '2026-0008', '2026-records', 2026);
        $survey = (new GtsSurvey())
            ->setUser($owner)
            ->setName('Records Survey, Owner')
            ->setEmailAddress('alumni-records-survey@example.com')
            ->setDynamicAnswers([
                'version' => 2,
                'responses' => [
                    [
                        'key' => 'q16',
                        'section' => 'D. Employment Data',
                        'questionText' => '16. Are you presently employed?',
                        'inputType' => 'radio',
                        'numberKey' => '16',
                        'answer' => 'Yes',
                    ],
                    [
                        'key' => 'q18',
                        'section' => 'D. Employment Data',
                        'questionText' => '18. Present Employment Status',
                        'inputType' => 'select',
                        'numberKey' => '18',
                        'answer' => 'Temporary',
                    ],
                    [
                        'key' => 'q19',
                        'section' => 'D. Employment Data',
                        'questionText' => '19. Present Occupation (PSOC Classification)',
                        'inputType' => 'select',
                        'numberKey' => '19',
                        'answer' => 'Professionals',
                    ],
                ],
            ]);

        $entityManager->persist($admin);
        $entityManager->persist($owner);
        $entityManager->persist($owner->getAlumni());
        $entityManager->persist($survey);
        $entityManager->flush();

        $adminId = $admin->getId();
        $surveyId = $survey->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $admin = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($adminId);
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($admin);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);
        $client->request('GET', '/api/admin/users?limit=100');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $items = $payload['items'] ?? [];
        $record = null;
        foreach ($items as $item) {
            if (($item['email'] ?? null) === 'alumni-records-survey@example.com') {
                $record = $item;
                break;
            }
        }

        self::assertIsArray($record);
        self::assertSame($surveyId, $record['alumni']['latestSurvey']['id'] ?? null);
        self::assertSame('Temporary', $record['alumni']['latestSurvey']['employmentStatus'] ?? null);
        self::assertSame('Professionals', $record['alumni']['latestSurvey']['occupation'] ?? null);
    }

    public function testShowUsesSavedSnapshotInsteadOfCurrentQuestionBankText(): void
    {
        $entityManager = $this->bootEntityManager();
        $staff = $this->createStaffUser('gts-show-staff@example.com');
        $owner = $this->createActiveAlumniUser('builder-show@example.com', '2022-0004', '2022-show');
        $updatedQuestion = $this->createQuestion('D. Employment Data', '19. Updated occupation label', 'text', 10);
        $survey = (new GtsSurvey())
            ->setUser($owner)
            ->setName('Snapshot Survey, Owner')
            ->setEmailAddress('builder-show@example.com')
            ->setDynamicAnswers([
                'version' => 2,
                'responses' => [
                    [
                        'key' => 'legacy-19',
                        'section' => 'D. Employment Data',
                        'questionText' => '19. Present Occupation (PSOC Classification)',
                        'inputType' => 'text',
                        'numberKey' => '19',
                        'answer' => 'Technicians and Associate Professionals',
                    ],
                ],
            ]);

        $entityManager->persist($staff);
        $entityManager->persist($owner);
        $entityManager->persist($owner->getAlumni());
        $entityManager->persist($updatedQuestion);
        $entityManager->persist($survey);
        $entityManager->flush();

        $staffId = $staff->getId();
        $surveyId = $survey->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $staff = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($staffId);
        $client->loginUser($staff);

        $client->request('GET', '/gts/' . $surveyId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '19. Present Occupation (PSOC Classification)');
        self::assertSelectorTextContains('body', 'Technicians and Associate Professionals');
        self::assertSelectorTextNotContains('body', '19. Updated occupation label');
    }

    public function testAdminNotificationsCanBeListedReadAndFiltered(): void
    {
        $entityManager = $this->bootEntityManager();
        $actor = $this->createAdminUser('notification-actor@example.com');
        $recipient = $this->createAdminUser('notification-recipient@example.com');

        $entityManager->persist($actor);
        $entityManager->persist($recipient);
        $entityManager->flush();

        $notificationService = static::getContainer()->get(NotificationService::class);
        $notificationService->createAdminNotification(
            'content.announcement_created',
            'Announcement created',
            'A new announcement is ready.',
            Notification::SEVERITY_INFO,
            '/announcements',
            'Announcement',
            10,
            actor: $actor,
        );
        $notificationService->createAdminNotification(
            'gts.response_submitted',
            'New GTS survey response',
            'An alumni submitted a tracer survey.',
            Notification::SEVERITY_SUCCESS,
            '/gts/responses',
            'GtsSurvey',
            11,
            actor: $actor,
        );

        $expired = (new Notification())
            ->setRecipient($recipient)
            ->setActor($actor)
            ->setType('expired.notice')
            ->setTitle('Expired')
            ->setMessage('This should be hidden.')
            ->setSeverity(Notification::SEVERITY_INFO)
            ->setTargetUrl('/audit-logs')
            ->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $entityManager->persist($expired);
        $entityManager->flush();

        self::assertCount(0, $notificationService->recentFor($actor));
        $recipientId = $recipient->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $recipient = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($recipientId);
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($recipient);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $token);

        $client->request('GET', '/api/admin/notifications?limit=20');
        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $payload['unreadCount']);
        self::assertCount(2, $payload['items']);
        self::assertSame('gts.response_submitted', $payload['items'][0]['type']);
        self::assertNotContains('expired.notice', array_column($payload['items'], 'type'));

        $firstNotificationId = $payload['items'][0]['id'];
        $client->request('PATCH', '/api/admin/notifications/' . $firstNotificationId . '/read');
        self::assertResponseIsSuccessful();
        $readPayload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $readPayload['unreadCount']);
        self::assertNotNull($readPayload['item']['readAt']);

        $client->request('PATCH', '/api/admin/notifications/read-all');
        self::assertResponseIsSuccessful();
        $readAllPayload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $readAllPayload['unreadCount']);

        $client->request('GET', '/api/admin/notifications/unread-count');
        self::assertResponseIsSuccessful();
        $countPayload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $countPayload['unreadCount']);
    }

    private function createActiveAlumniUser(string $email, string $schoolId, string $studentNumber, int $batchYear = 2022): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Ava')
            ->setLastName('Alumni')
            ->setSchoolId($schoolId)
            ->setRoles([User::ROLE_ALUMNI])
            ->setPassword('Password1!')
            ->setAccountStatus('active');

        $alumni = (new Alumni())
            ->setStudentNumber($studentNumber)
            ->setFirstName('Ava')
            ->setLastName('Alumni')
            ->setEmailAddress($email)
            ->setYearGraduated($batchYear)
            ->setTracerStatus('UNTRACED');

        $user->setAlumni($alumni);
        $alumni->setUser($user);

        return $user;
    }

    private function createStaffUser(string $email): User
    {
        return (new User())
            ->setEmail($email)
            ->setFirstName('Stacy')
            ->setLastName('Staff')
            ->setRoles(['ROLE_STAFF'])
            ->setPassword('Password1!')
            ->setAccountStatus('active');
    }

    private function createAdminUser(string $email): User
    {
        return (new User())
            ->setEmail($email)
            ->setFirstName('Ada')
            ->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('Password1!')
            ->setAccountStatus('active');
    }

    private function createQuestion(string $section, string $text, string $type, int $sortOrder, ?array $options = null): GtsSurveyQuestion
    {
        return (new GtsSurveyQuestion())
            ->setSection($section)
            ->setQuestionText($text)
            ->setInputType($type)
            ->setOptions($options)
            ->setSortOrder($sortOrder)
            ->setIsActive(true);
    }

    private function bootEntityManager(): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        return static::getContainer()->get(ManagerRegistry::class)->getManager();
    }
}
