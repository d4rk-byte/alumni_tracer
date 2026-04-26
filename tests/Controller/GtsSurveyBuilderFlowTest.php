<?php

namespace App\Tests\Controller;

use App\Entity\Alumni;
use App\Entity\GtsSurvey;
use App\Entity\GtsSurveyQuestion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
        $entityManager->persist(
            $this->createQuestion('Custom Section', 'Custom builder question', 'text', 10)
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

    private function createActiveAlumniUser(string $email, string $schoolId, string $studentNumber): User
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
            ->setYearGraduated(2022)
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