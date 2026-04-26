<?php

namespace App\Tests\Command;

use App\Command\SyncAlumniUsersCommand;
use App\Entity\Alumni;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SyncAlumniUsersCommandTest extends KernelTestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = dirname(__DIR__, 2) . '/var/test_sync_alumni_' . uniqid('', true) . '.sqlite';
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

    public function testCommandCreatesAlumniWhenSchoolIdIsPresent(): void
    {
        $entityManager = $this->bootEntityManager();
        $entityManager->persist($this->createAlumniUser('create@example.com', 'Cara', 'Create', '2021-00001'));
        $entityManager->flush();

        self::ensureKernelShutdown();

        $commandTester = $this->createCommandTester();
        $exitCode = $commandTester->execute([]);
        $display = preg_replace('/\s+/', ' ', $commandTester->getDisplay()) ?? $commandTester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Created 1 Alumni record(s), linked 0 existing record(s), skipped 0 account(s).', $display);

        $entityManager = $this->bootEntityManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'create@example.com']);
        $alumni = $entityManager->getRepository(Alumni::class)->findOneBy(['emailAddress' => 'create@example.com']);

        self::assertNotNull($user?->getAlumni());
        self::assertNotNull($alumni);
        self::assertSame('2021-00001', $alumni->getStudentNumber());
    }

    public function testCommandLinksExistingAlumniByEmail(): void
    {
        $entityManager = $this->bootEntityManager();
        $entityManager->persist(
            (new Alumni())
                ->setStudentNumber('legacy-001')
                ->setFirstName('Lina')
                ->setLastName('Link')
                ->setEmailAddress('link@example.com')
                ->setYearGraduated(2019)
        );
        $entityManager->persist($this->createAlumniUser('link@example.com', 'Lina', 'Link', null));
        $entityManager->flush();

        self::ensureKernelShutdown();

        $commandTester = $this->createCommandTester();
        $exitCode = $commandTester->execute([]);
        $display = preg_replace('/\s+/', ' ', $commandTester->getDisplay()) ?? $commandTester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Created 0 Alumni record(s), linked 1 existing record(s), skipped 0 account(s).', $display);

        $entityManager = $this->bootEntityManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'link@example.com']);
        $alumniMatches = $entityManager->getRepository(Alumni::class)->findBy(['emailAddress' => 'link@example.com']);

        self::assertNotNull($user?->getAlumni());
        self::assertCount(1, $alumniMatches);
    }

    public function testCommandSkipsUserWithoutSchoolIdAndNoMatchingAlumni(): void
    {
        $entityManager = $this->bootEntityManager();
        $entityManager->persist($this->createAlumniUser('skip@example.com', 'Sia', 'Skip', null));
        $entityManager->flush();

        self::ensureKernelShutdown();

        $commandTester = $this->createCommandTester();
        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('skipped 1 account(s)', $commandTester->getDisplay());
        self::assertStringContainsString('no school ID was found', $commandTester->getDisplay());

        $entityManager = $this->bootEntityManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'skip@example.com']);

        self::assertNull($user?->getAlumni());
        self::assertCount(0, $entityManager->getRepository(Alumni::class)->findBy(['emailAddress' => 'skip@example.com']));
    }

    public function testCommandSkipsConflictingAlumniMatches(): void
    {
        $entityManager = $this->bootEntityManager();
        $entityManager->persist(
            (new Alumni())
                ->setStudentNumber('2024-00011')
                ->setFirstName('Email')
                ->setLastName('Match')
                ->setEmailAddress('conflict@example.com')
                ->setYearGraduated(2024)
        );
        $entityManager->persist(
            (new Alumni())
                ->setStudentNumber('2024-00099')
                ->setFirstName('School')
                ->setLastName('Match')
                ->setEmailAddress('other@example.com')
                ->setYearGraduated(2024)
        );
        $entityManager->persist($this->createAlumniUser('conflict@example.com', 'Cora', 'Conflict', '2024-00099'));
        $entityManager->flush();

        self::ensureKernelShutdown();

        $commandTester = $this->createCommandTester();
        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('matched different alumni records', $commandTester->getDisplay());
        self::assertStringContainsString('skipped 1 account(s)', $commandTester->getDisplay());

        $entityManager = $this->bootEntityManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'conflict@example.com']);

        self::assertNull($user?->getAlumni());
    }

    private function createCommandTester(): CommandTester
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $application = new Application(self::$kernel);
        $command = static::getContainer()->get(SyncAlumniUsersCommand::class);
        $application->addCommand($command);

        return new CommandTester($application->find('app:sync-alumni-users'));
    }

    private function createAlumniUser(string $email, string $firstName, string $lastName, ?string $schoolId): User
    {
        return (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setRoles([User::ROLE_ALUMNI])
            ->setPassword('Password1!')
            ->setAccountStatus('active')
            ->setSchoolId($schoolId);
    }

    private function bootEntityManager(): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        return static::getContainer()->get(ManagerRegistry::class)->getManager();
    }
}