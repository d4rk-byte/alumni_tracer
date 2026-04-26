<?php

namespace App\Tests\Controller;

use App\Entity\Alumni;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegistrationControllerTest extends WebTestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = dirname(__DIR__, 2) . '/var/test_registration_' . uniqid('', true) . '.sqlite';
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

    public function testRegisterCreatesPendingUserLinkedToNewAlumni(): void
    {
        $client = static::createClient();

        $client->request('GET', '/register');
        $client->submitForm('Register', $this->registrationPayload([
            'registration_form[schoolId]' => '2022-00123',
            'registration_form[firstName]' => 'Ana',
            'registration_form[lastName]' => 'Lopez',
            'registration_form[yearGraduated]' => '2022',
            'registration_form[email]' => 'ana@example.com',
        ]));

        self::assertResponseRedirects('/login');

        $entityManager = $this->bootEntityManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'ana@example.com']);

        self::assertNotNull($user);
        self::assertSame('pending', $user->getAccountStatus());
        self::assertContains(User::ROLE_ALUMNI, $user->getRoles());
        self::assertSame('2022-00123', $user->getSchoolId());
        self::assertNotNull($user->getAlumni());
        self::assertSame(2022, $user->getAlumni()?->getYearGraduated());
        self::assertSame($user->getId(), $user->getAlumni()?->getUser()?->getId());
    }

    public function testRegisterLinksExistingAlumniWhenIdentifiersMatchSameRecord(): void
    {
        $entityManager = $this->bootEntityManager();
        $existingAlumni = (new Alumni())
            ->setStudentNumber('2022-00456')
            ->setFirstName('Ben')
            ->setLastName('Santos')
            ->setEmailAddress('ben@example.com')
            ->setYearGraduated(2022);

        $entityManager->persist($existingAlumni);
        $entityManager->flush();
        $existingAlumniId = $existingAlumni->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();

        $client->request('GET', '/register');
        $client->submitForm('Register', $this->registrationPayload([
            'registration_form[schoolId]' => '2022-00456',
            'registration_form[firstName]' => 'Ben',
            'registration_form[lastName]' => 'Santos',
            'registration_form[yearGraduated]' => '2022',
            'registration_form[email]' => 'ben@example.com',
        ]));

        self::assertResponseRedirects('/login');

        $entityManager = $this->bootEntityManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'ben@example.com']);
        $alumni = $entityManager->getRepository(Alumni::class)->findOneBy(['emailAddress' => 'ben@example.com']);

        self::assertNotNull($user);
        self::assertNotNull($alumni);
        self::assertSame($existingAlumniId, $alumni->getId());
        self::assertSame($user->getId(), $alumni->getUser()?->getId());
        self::assertCount(1, $entityManager->getRepository(Alumni::class)->findBy(['emailAddress' => 'ben@example.com']));
    }

    public function testRegisterShowsConflictWhenIdentifiersPointToDifferentAlumniRecords(): void
    {
        $entityManager = $this->bootEntityManager();
        $entityManager->persist(
            (new Alumni())
                ->setStudentNumber('2021-00001')
                ->setFirstName('Existing')
                ->setLastName('Email')
                ->setEmailAddress('split@example.com')
                ->setYearGraduated(2021)
        );
        $entityManager->persist(
            (new Alumni())
                ->setStudentNumber('2021-00099')
                ->setFirstName('Existing')
                ->setLastName('Student')
                ->setEmailAddress('other@example.com')
                ->setYearGraduated(2021)
        );
        $entityManager->flush();

        self::ensureKernelShutdown();

        $client = static::createClient();

        $client->request('GET', '/register');
        $client->submitForm('Register', $this->registrationPayload([
            'registration_form[schoolId]' => '2021-00099',
            'registration_form[firstName]' => 'Mia',
            'registration_form[lastName]' => 'Conflict',
            'registration_form[yearGraduated]' => '2021',
            'registration_form[email]' => 'split@example.com',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'matched different alumni records');

        $entityManager = $this->bootEntityManager();
        self::assertNull($entityManager->getRepository(User::class)->findOneBy(['email' => 'split@example.com']));
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'registration_form[schoolId]' => '2022-00123',
            'registration_form[firstName]' => 'Test',
            'registration_form[lastName]' => 'User',
            'registration_form[yearGraduated]' => '2022',
            'registration_form[email]' => 'test@example.com',
            'registration_form[plainPassword][first]' => 'Password1!',
            'registration_form[plainPassword][second]' => 'Password1!',
            'registration_form[dataPrivacyConsent]' => '1',
        ], $overrides);
    }

    private function bootEntityManager(): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        return static::getContainer()->get(ManagerRegistry::class)->getManager();
    }
}