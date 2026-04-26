<?php

namespace App\Tests\Controller;

use App\Entity\Alumni;
use App\Entity\Communication;
use App\Entity\QrRegistrationBatch;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminEmailControllerTest extends WebTestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = dirname(__DIR__, 2) . '/var/test_admin_email_' . uniqid('', true) . '.sqlite';
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

    public function testEmailPageUsesGeneralRegistrationLinkWhenNoYearIsSelected(): void
    {
        $adminId = $this->seedAdminWithRecipients();

        $client = static::createClient();
        $admin = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($adminId);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/email');

        self::assertResponseIsSuccessful();
        self::assertSame('http://localhost/register', $crawler->filter('[data-registration-link-preview]')->attr('value'));
        self::assertSelectorTextContains('[data-email-link-mode="general"]', 'General registration link');
        self::assertSelectorTextContains('body', 'This screen saves drafts and recipient previews only. It does not deliver email.');
    }

    public function testEmailPageShowsBatchLinkWarningWhenQrBatchIsMissing(): void
    {
        $adminId = $this->seedAdminWithRecipients();

        $client = static::createClient();
        $admin = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($adminId);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/email?email_recipient_filter%5ByearGraduated%5D=2022');

        self::assertResponseIsSuccessful();
        self::assertSame('http://localhost/register/qr/2022', $crawler->filter('[data-registration-link-preview]')->attr('value'));
        self::assertSelectorTextContains('[data-email-link-mode="batch"]', 'Batch 2022 QR registration link');
        self::assertSelectorTextContains('[data-registration-link-warning]', 'Batch 2022 does not have an active QR registration page yet.');
    }

    public function testSavingDraftStoresOutboxEntryForSelectedYear(): void
    {
        $entityManager = $this->bootEntityManager();
        $admin = $this->createAdmin();
        $entityManager->persist($admin);
        $entityManager->persist($this->createRecipient('Ava', 'Invite', 'ava@example.com', 2022));
        $entityManager->persist((new QrRegistrationBatch())->setBatchYear(2022));
        $entityManager->flush();
        $adminId = $admin->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();
        $admin = static::getContainer()->get(ManagerRegistry::class)->getManager()->getRepository(User::class)->find($adminId);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/email?email_recipient_filter%5ByearGraduated%5D=2022');
        $client->submitForm('Save Draft to Outbox', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'subject' => 'Batch 2022 Invite',
            'message' => 'Use the registration link in this draft.',
        ]);

        self::assertResponseRedirects('/admin/email?email_recipient_filter%5ByearGraduated%5D=2022');

        $entityManager = $this->bootEntityManager();
        $communication = $entityManager->getRepository(Communication::class)->findOneBy(['subject' => 'Batch 2022 Invite']);

        self::assertNotNull($communication);
        self::assertSame(1, $communication->getRecipientCount());
        self::assertSame('2022', $communication->getTargetYear());
        self::assertSame('email', $communication->getChannel());
    }

    private function seedAdminWithRecipients(): int
    {
        $entityManager = $this->bootEntityManager();
        $admin = $this->createAdmin();
        $entityManager->persist($admin);
        $entityManager->persist($this->createRecipient('Ava', 'Invite', 'ava@example.com', 2022));
        $entityManager->persist($this->createRecipient('Gio', 'General', 'gio@example.com', 2021));
        $entityManager->flush();
        $adminId = $admin->getId();

        self::ensureKernelShutdown();

        return $adminId;
    }

    private function createAdmin(): User
    {
        return (new User())
            ->setEmail('admin@example.com')
            ->setFirstName('Iris')
            ->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('Password1!')
            ->setAccountStatus('active');
    }

    private function createRecipient(string $firstName, string $lastName, string $emailAddress, int $yearGraduated): Alumni
    {
        return (new Alumni())
            ->setStudentNumber(sprintf('%d-%s', $yearGraduated, strtolower($firstName)))
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