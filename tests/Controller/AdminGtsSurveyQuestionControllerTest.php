<?php

namespace App\Tests\Controller;

use App\Entity\GtsSurveyQuestion;
use App\Entity\GtsSurveyTemplate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminGtsSurveyQuestionControllerTest extends WebTestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = dirname(__DIR__, 2) . '/var/test_gts_questions_' . uniqid('', true) . '.sqlite';
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

    public function testBuilderShowsSingleFormWorkspaceAndWholeFormPreviewLink(): void
    {
        $entityManager = $this->bootEntityManager();
        $staff = $this->createStaffUser();
        $surveyTemplate = $this->createSurveyTemplate('Graduate Tracer Survey Form');
        $entityManager->persist($staff);
        $entityManager->persist($surveyTemplate);
        $entityManager->persist(
            $this->createQuestion($surveyTemplate, 'A. General Information', '4. Telephone / Contact Number(s)', 'text', 10)
        );
        $entityManager->persist(
            $this->createQuestion($surveyTemplate, 'D. Employment Data', '19. Present Occupation (PSOC Classification)', 'select', 20, ['Professionals'])
        );
        $entityManager->flush();
        $staffId = $staff->getId();
        $templateId = $surveyTemplate->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $staff = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($staffId);
        $client->loginUser($staff);

        $crawler = $client->request('GET', '/staff/gts/surveys/' . $templateId . '/questions');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Graduate Tracer Survey Form');
        self::assertSelectorTextContains('body', '2');
        self::assertSame('/staff/gts/surveys/' . $templateId . '/preview', $crawler->filter('[data-view-whole-form]')->attr('href'));
    }

    public function testStaffCanOpenWholeFormPreviewFromBuilder(): void
    {
        $entityManager = $this->bootEntityManager();
        $staff = $this->createStaffUser();
        $surveyTemplate = $this->createSurveyTemplate('Graduate Tracer Survey Form');
        $entityManager->persist($staff);
        $entityManager->persist($surveyTemplate);
        $entityManager->persist(
            $this->createQuestion($surveyTemplate, 'Custom Section', 'Custom whole form preview question', 'text', 10)
        );
        $entityManager->flush();
        $staffId = $staff->getId();
        $templateId = $surveyTemplate->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $staff = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($staffId);
        $client->loginUser($staff);

        $client->request('GET', '/staff/gts/surveys/' . $templateId . '/preview');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Custom whole form preview question');
        self::assertSelectorTextContains('body', 'ADMIN PREVIEW MODE');
    }

    private function createStaffUser(): User
    {
        return (new User())
            ->setEmail('staff@example.com')
            ->setFirstName('Stacy')
            ->setLastName('Staff')
            ->setRoles(['ROLE_STAFF'])
            ->setPassword('Password1!')
            ->setAccountStatus('active');
    }

    private function createSurveyTemplate(string $title): GtsSurveyTemplate
    {
        return (new GtsSurveyTemplate())
            ->setTitle($title)
            ->setDescription('Template used for question builder tests.')
            ->setIsActive(true);
    }

    private function createQuestion(GtsSurveyTemplate $surveyTemplate, string $section, string $questionText, string $inputType, int $sortOrder, ?array $options = null): GtsSurveyQuestion
    {
        return (new GtsSurveyQuestion())
            ->setSurveyTemplate($surveyTemplate)
            ->setSection($section)
            ->setQuestionText($questionText)
            ->setInputType($inputType)
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