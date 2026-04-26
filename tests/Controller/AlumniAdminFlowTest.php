<?php

namespace App\Tests\Controller;

use App\Entity\Alumni;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AlumniAdminFlowTest extends WebTestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = dirname(__DIR__, 2) . '/var/test_alumni_admin_' . uniqid('', true) . '.sqlite';
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

    public function testAdminDirectoryShowsRegistrationStatesAndFiltersPending(): void
    {
        $entityManager = $this->bootEntityManager();
        $admin = $this->createUser('admin@example.com', 'Ada', 'Admin', ['ROLE_ADMIN']);
        $entityManager->persist($admin);

        $entityManager->persist(
            $this->createAlumniRecord('2020-00001', 'Una', 'Listed', 'una@example.com', 2020)
                ->setCourse('BSIT')
        );

        $pendingUser = $this->createUser('pending@example.com', 'Penny', 'Pending', [User::ROLE_ALUMNI], 'pending', '2021-00002');
        $pendingAlumni = $this->createAlumniRecord('2021-00002', 'Penny', 'Pending', 'pending@example.com', 2021)
            ->setCourse('BSCS')
            ->setUser($pendingUser);
        $pendingUser->setAlumni($pendingAlumni);
        $entityManager->persist($pendingUser);
        $entityManager->persist($pendingAlumni);

        $activeUser = $this->createUser('active@example.com', 'Axel', 'Active', [User::ROLE_ALUMNI], 'active', '2022-00003');
        $activeAlumni = $this->createAlumniRecord('2022-00003', 'Axel', 'Active', 'active@example.com', 2022)
            ->setCourse('BSIS')
            ->setUser($activeUser);
        $activeUser->setAlumni($activeAlumni);
        $entityManager->persist($activeUser);
        $entityManager->persist($activeAlumni);

        $entityManager->flush();
        $adminId = $admin->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $admin = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($adminId);
        $client->loginUser($admin);

        $client->request('GET', '/alumni/');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Unregistered', $content);
        self::assertStringContainsString('Pending Approval', $content);
        self::assertStringContainsString('Active Account', $content);
        self::assertStringContainsString('Una, Listed', str_replace('Listed, Una', 'Una, Listed', $content));
        self::assertStringContainsString('Penny, Pending', str_replace('Pending, Penny', 'Penny, Pending', $content));
        self::assertStringContainsString('Axel, Active', str_replace('Active, Axel', 'Axel, Active', $content));

        $client->request('GET', '/alumni/?registration_state=pending');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Pending, Penny', $content);
        self::assertStringNotContainsString('Listed, Una', $content);
        self::assertStringNotContainsString('Active, Axel', $content);
    }

    public function testVerificationQueueLinksToMatchingAlumniRecord(): void
    {
        $entityManager = $this->bootEntityManager();
        $staff = $this->createUser('staff@example.com', 'Sam', 'Staff', ['ROLE_STAFF']);
        $pendingUser = $this->createUser('review@example.com', 'Rina', 'Review', [User::ROLE_ALUMNI], 'pending', '2023-00004');
        $pendingAlumni = $this->createAlumniRecord('2023-00004', 'Rina', 'Review', 'review@example.com', 2023)
            ->setUser($pendingUser);
        $pendingUser->setAlumni($pendingAlumni);

        $entityManager->persist($staff);
        $entityManager->persist($pendingUser);
        $entityManager->persist($pendingAlumni);
        $entityManager->flush();

        $staffId = $staff->getId();
        $alumniId = $pendingAlumni->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $staff = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($staffId);
        $client->loginUser($staff);

        $client->request('GET', '/verification/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('a[href="/alumni/%d/edit"]', $alumniId));
        self::assertSelectorTextContains('body', 'Review alumni');
        self::assertSelectorTextContains('body', '2023-00004');
    }

    private function createUser(
        string $email,
        string $firstName,
        string $lastName,
        array $roles,
        string $accountStatus = 'active',
        ?string $schoolId = null,
    ): User {
        return (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setRoles($roles)
            ->setPassword('Password1!')
            ->setAccountStatus($accountStatus)
            ->setSchoolId($schoolId);
    }

    private function createAlumniRecord(
        string $studentNumber,
        string $firstName,
        string $lastName,
        string $emailAddress,
        int $yearGraduated,
    ): Alumni {
        return (new Alumni())
            ->setStudentNumber($studentNumber)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setEmailAddress($emailAddress)
            ->setYearGraduated($yearGraduated);
    }

    private function bootEntityManager(): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        return static::getContainer()->get(ManagerRegistry::class)->getManager();
    }
}