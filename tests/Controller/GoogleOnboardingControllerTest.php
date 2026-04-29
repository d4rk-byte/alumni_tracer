<?php

namespace App\Tests\Controller;

use App\Entity\Alumni;
use App\Entity\College;
use App\Entity\Department;
use App\Entity\QrRegistrationBatch;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GoogleOnboardingControllerTest extends WebTestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = dirname(__DIR__, 2) . '/var/test_google_onboarding_' . uniqid('', true) . '.sqlite';
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

    public function testIncompleteGoogleUserIsRedirectedToOnboarding(): void
    {
        $user = $this->createGoogleUser(true);

        $client = static::createClient();
        $client->loginUser($user);
        $client->request('GET', '/home');

        self::assertResponseRedirects('/connect/google/onboarding');
    }

    public function testGoogleOnboardingCompletesProfileAndAllowsAccess(): void
    {
        $entityManager = $this->bootEntityManager();
        $batch = (new QrRegistrationBatch())
            ->setBatchYear(2022);
        $college = (new College())
            ->setName('College of Computer Studies')
            ->setCode('CCS')
            ->setIsActive(true);
        $department = (new Department())
            ->setName('Computer Science')
            ->setCode('BSCS')
            ->setCollege($college)
            ->setIsActive(true);

        $entityManager->persist($batch);
        $entityManager->persist($college);
        $entityManager->persist($department);
        $entityManager->flush();

        $user = $this->createGoogleUser(true);

        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->loginUser($user);

        $client->request('GET', '/connect/google/onboarding');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Complete your alumni profile');

        $client->submitForm('Save and Continue', [
            'google_onboarding[schoolId]' => '2022-00444',
            'google_onboarding[firstName]' => 'Maya',
            'google_onboarding[middleName]' => 'Reyes',
            'google_onboarding[lastName]' => 'Santos',
            'google_onboarding[yearGraduated]' => '2022',
            'google_onboarding[college]' => 'College of Computer Studies',
            'google_onboarding[department]' => 'Computer Science',
        ]);

        self::assertResponseRedirects('/home');

        $entityManager = $this->bootEntityManager();
        $savedUser = $entityManager->getRepository(User::class)->findOneBy(['email' => 'google@example.com']);

        self::assertNotNull($savedUser);
        self::assertFalse($savedUser->isRequiresOnboarding());
        self::assertNotNull($savedUser->getProfileCompletedAt());
        self::assertSame('2022-00444', $savedUser->getSchoolId());
        self::assertNotNull($savedUser->getAlumni());
        self::assertSame('College of Computer Studies', $savedUser->getAlumni()?->getCollege());
        self::assertSame('Computer Science', $savedUser->getAlumni()?->getDegreeProgram());
        self::assertSame('BSCS', $savedUser->getAlumni()?->getCourse());

        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function createGoogleUser(bool $requiresOnboarding): User
    {
        $entityManager = $this->bootEntityManager();
        $user = (new User())
            ->setEmail('google@example.com')
            ->setFirstName('Google')
            ->setLastName('User')
            ->setRoles([User::ROLE_ALUMNI])
            ->setAccountStatus('active')
            ->setGoogleSubject('google-subject-123')
            ->setEmailVerifiedAt(new \DateTimeImmutable())
            ->setRequiresOnboarding($requiresOnboarding)
            ->setDpaConsent(true)
            ->setDpaConsentDate(new \DateTime())
            ->setPassword('google-password-hash');

        $entityManager->persist($user);
        $entityManager->flush();

        self::ensureKernelShutdown();

        return $user;
    }

    private function bootEntityManager(): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        return static::getContainer()->get(ManagerRegistry::class)->getManager();
    }
}