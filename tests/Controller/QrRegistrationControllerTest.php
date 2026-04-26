<?php

namespace App\Tests\Controller;

use App\Entity\College;
use App\Entity\Department;
use App\Entity\QrRegistrationBatch;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class QrRegistrationControllerTest extends WebTestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = dirname(__DIR__, 2) . '/var/test_qr_registration_' . uniqid('', true) . '.sqlite';
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

    public function testQrRegisterCreatesActiveUserAndSignsIn(): void
    {
        $entityManager = $this->bootEntityManager();

        $college = (new College())
            ->setName('College of Engineering')
            ->setCode('COE')
            ->setIsActive(true);
        $department = (new Department())
            ->setName('Computer Engineering')
            ->setCode('CPE')
            ->setCollege($college)
            ->setIsActive(true);
        $batch = (new QrRegistrationBatch())
            ->setBatchYear(2022);

        $entityManager->persist($college);
        $entityManager->persist($department);
        $entityManager->persist($batch);
        $entityManager->flush();

        $collegeId = $college->getId();
        $departmentId = $department->getId();

        self::ensureKernelShutdown();

        $client = static::createClient();

        $client->request('GET', '/register/qr/2022');
        $client->submitForm('Create Account and Continue', [
            'qr_registration_form[college]' => (string) $collegeId,
            'qr_registration_form[department]' => (string) $departmentId,
            'qr_registration_form[studentId]' => '2022-00999',
            'qr_registration_form[firstName]' => 'Lia',
            'qr_registration_form[middleName]' => 'Reyes',
            'qr_registration_form[lastName]' => 'Garcia',
            'qr_registration_form[email]' => 'lia@example.com',
            'qr_registration_form[plainPassword][first]' => 'Password1!',
            'qr_registration_form[plainPassword][second]' => 'Password1!',
            'qr_registration_form[dataPrivacyConsent]' => '1',
        ]);

        self::assertResponseRedirects();

        for ($redirectCount = 0; $client->getResponse()->isRedirection() && $redirectCount < 5; ++$redirectCount) {
            $client->followRedirect();
        }

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Graduate Tracer Survey');

        $entityManager = $this->bootEntityManager();
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'lia@example.com']);

        self::assertNotNull($user);
        self::assertSame('active', $user->getAccountStatus());
        self::assertNotNull($user->getAlumni());
        self::assertSame(2022, $user->getAlumni()?->getYearGraduated());
        self::assertSame('College of Engineering', $user->getAlumni()?->getCollege());
        self::assertSame('CPE', $user->getAlumni()?->getCourse());
        self::assertSame('Computer Engineering', $user->getAlumni()?->getDegreeProgram());
        self::assertSame('Reyes', $user->getAlumni()?->getMiddleName());
    }

    private function bootEntityManager(): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        return static::getContainer()->get(ManagerRegistry::class)->getManager();
    }
}