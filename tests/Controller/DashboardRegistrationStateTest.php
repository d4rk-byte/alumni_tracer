<?php

namespace App\Tests\Controller;

use App\Entity\Alumni;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardRegistrationStateTest extends WebTestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = dirname(__DIR__, 2) . '/var/test_dashboard_registration_' . uniqid('', true) . '.sqlite';
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

    public function testAdminDashboardShowsRegistrationStateSnapshot(): void
    {
        $entityManager = $this->bootEntityManager();
        $admin = $this->createUser('admin@example.com', 'Adele', 'Admin', ['ROLE_ADMIN']);
        $entityManager->persist($admin);
        $this->seedRegistrationStateSet($entityManager);
        $entityManager->flush();
        $adminId = $admin->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        static::getContainer()->get('cache.app')->clear();
        $admin = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($adminId);
        $client->loginUser($admin);

        $client->request('GET', '/admin/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-registration-state-card="unregistered"]', 'Unregistered Alumni');
        self::assertSelectorTextContains('[data-registration-state-card="unregistered"]', '1');
        self::assertSelectorTextContains('[data-registration-state-card="pending"]', '1');
        self::assertSelectorTextContains('[data-registration-state-card="active"]', '1');
        self::assertSelectorTextContains('[data-registration-state-card="inactive"]', '1');
        self::assertSelectorExists('a[href="/alumni/?registration_state=pending"]');
    }

    public function testStaffDashboardShowsRegistrationStateSnapshot(): void
    {
        $entityManager = $this->bootEntityManager();
        $staff = $this->createUser('staff@example.com', 'Sofia', 'Staff', ['ROLE_STAFF']);
        $entityManager->persist($staff);
        $this->seedRegistrationStateSet($entityManager);
        $entityManager->flush();
        $staffId = $staff->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        static::getContainer()->get('cache.app')->clear();
        $staff = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($staffId);
        $client->loginUser($staff);

        $client->request('GET', '/staff');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-registration-state-card="unregistered"]', '1');
        self::assertSelectorTextContains('[data-registration-state-card="pending"]', '1');
        self::assertSelectorTextContains('[data-registration-state-card="active"]', '1');
        self::assertSelectorTextContains('[data-registration-state-card="inactive"]', '1');
        self::assertSelectorExists('a[href="/alumni/?registration_state=active"]');
    }

    private function seedRegistrationStateSet(EntityManagerInterface $entityManager): void
    {
        $entityManager->persist($this->createAlumni('2020-00001', 'Una', 'Listed', 'una@example.com', 2020));

        $pendingUser = $this->createUser('pending@example.com', 'Penny', 'Pending', [User::ROLE_ALUMNI], 'pending', '2021-00002');
        $pendingAlumni = $this->createAlumni('2021-00002', 'Penny', 'Pending', 'pending@example.com', 2021)->setUser($pendingUser);
        $pendingUser->setAlumni($pendingAlumni);
        $entityManager->persist($pendingUser);
        $entityManager->persist($pendingAlumni);

        $activeUser = $this->createUser('active@example.com', 'Axel', 'Active', [User::ROLE_ALUMNI], 'active', '2022-00003');
        $activeAlumni = $this->createAlumni('2022-00003', 'Axel', 'Active', 'active@example.com', 2022)->setUser($activeUser);
        $activeUser->setAlumni($activeAlumni);
        $entityManager->persist($activeUser);
        $entityManager->persist($activeAlumni);

        $inactiveUser = $this->createUser('inactive@example.com', 'Ina', 'Inactive', [User::ROLE_ALUMNI], 'inactive', '2023-00004');
        $inactiveAlumni = $this->createAlumni('2023-00004', 'Ina', 'Inactive', 'inactive@example.com', 2023)->setUser($inactiveUser);
        $inactiveUser->setAlumni($inactiveAlumni);
        $entityManager->persist($inactiveUser);
        $entityManager->persist($inactiveAlumni);
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

    private function createAlumni(
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