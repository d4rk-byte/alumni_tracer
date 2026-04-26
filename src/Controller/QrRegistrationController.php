<?php

namespace App\Controller;

use App\Entity\Alumni;
use App\Entity\College;
use App\Entity\Department;
use App\Entity\QrRegistrationBatch;
use App\Form\QrRegistrationFormType;
use App\Repository\CollegeRepository;
use App\Repository\DepartmentRepository;
use App\Repository\QrRegistrationBatchRepository;
use App\Service\AlumniRegistrationService;
use App\Service\RegistrationValidationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class QrRegistrationController extends AbstractController
{
    #[Route('/admin/qr-registration', name: 'admin_qr_registration', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminGenerator(
        Request $request,
        QrRegistrationBatchRepository $batchRepository,
        EntityManagerInterface $entityManager,
    ): Response
    {
        $this->ensureBatchTableExists($entityManager);

        $defaultYear = (int) date('Y');
        $maxYear = $defaultYear + 10;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('create_qr_batch', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Invalid security token. Please try again.');
                return $this->redirectToRoute('admin_qr_registration');
            }

            $batchYear = $request->request->getInt('batchYear', $defaultYear);
            if ($batchYear < 1950 || $batchYear > $maxYear) {
                $this->addFlash('error', sprintf('Batch year must be between 1950 and %d.', $maxYear));
                return $this->redirectToRoute('admin_qr_registration');
            }

            if ($batchRepository->findOneByBatchYear($batchYear) !== null) {
                $this->addFlash('error', sprintf('Batch %d already exists.', $batchYear));
                return $this->redirectToRoute('admin_qr_registration');
            }

            try {
                $entityManager->persist((new QrRegistrationBatch())->setBatchYear($batchYear));
                $entityManager->flush();
                $this->addFlash('success', sprintf('Batch %d QR registration created.', $batchYear));
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', sprintf('Batch %d already exists.', $batchYear));
            }

            return $this->redirectToRoute('admin_qr_registration');
        }

        $batches = $batchRepository->findAllOrdered();

        return $this->render('admin/qr_registration/index.html.twig', [
            'defaultBatchYear' => $defaultYear,
            'maxBatchYear' => $maxYear,
            'batches' => $batches,
        ]);
    }

    #[Route('/admin/qr-registration/{id}/delete', name: 'admin_qr_registration_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteBatch(int $id, Request $request, QrRegistrationBatchRepository $batchRepository, EntityManagerInterface $entityManager): Response
    {
        $this->ensureBatchTableExists($entityManager);

        $batch = $batchRepository->find($id);
        if (!$batch instanceof QrRegistrationBatch) {
            throw $this->createNotFoundException('QR registration batch was not found.');
        }

        if ($this->isCsrfTokenValid('delete_qr_batch' . $batch->getId(), (string) $request->request->get('_token'))) {
            $year = $batch->getBatchYear();
            $entityManager->remove($batch);
            $entityManager->flush();
            $this->addFlash('success', sprintf('Batch %d QR registration deleted.', $year));
        }

        return $this->redirectToRoute('admin_qr_registration');
    }

    #[Route('/register/qr/{batchYear<\d{4}>}', name: 'app_qr_registration', methods: ['GET', 'POST'])]
    public function register(
        int $batchYear,
        Request $request,
        QrRegistrationBatchRepository $batchRepository,
        CollegeRepository $collegeRepository,
        DepartmentRepository $departmentRepository,
        AlumniRegistrationService $registrationService,
        EntityManagerInterface $entityManager,
        Security $security,
    ): Response {
        $this->ensureBatchTableExists($entityManager);

        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $currentYear = (int) date('Y');
        if ($batchYear < 1950 || $batchYear > $currentYear + 10) {
            throw $this->createNotFoundException('Batch year is invalid.');
        }

        if ($batchRepository->findOneByBatchYear($batchYear) === null) {
            throw $this->createNotFoundException('QR registration batch was not found.');
        }

        $activeDepartments = $departmentRepository->findActiveWithActiveCollege();
        $collegeIdsWithDepartments = [];

        foreach ($activeDepartments as $department) {
            $college = $department->getCollege();

            if ($college instanceof College && $college->getId() !== null) {
                $collegeIdsWithDepartments[$college->getId()] = true;
            }
        }

        $colleges = array_values(array_filter(
            $collegeRepository->findActive(),
            static fn (College $college): bool => $college->getId() !== null && isset($collegeIdsWithDepartments[$college->getId()])
        ));

        $form = $this->createForm(QrRegistrationFormType::class, null, [
            'college_choices' => $colleges,
            'department_choices' => $activeDepartments,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedCollege = $form->get('college')->getData();
            $selectedDepartment = $form->get('department')->getData();
            $email = strtolower(trim((string) $form->get('email')->getData()));
            $studentId = trim((string) $form->get('studentId')->getData());
            $firstName = trim((string) $form->get('firstName')->getData());
            $middleName = trim((string) $form->get('middleName')->getData());
            $lastName = trim((string) $form->get('lastName')->getData());
            $plainPassword = (string) $form->get('plainPassword')->getData();
            $hasErrors = false;

            if (!$selectedCollege instanceof College || $selectedCollege->isIsActive() !== true) {
                $form->get('college')->addError(new FormError('Please select an active college.'));
                $hasErrors = true;
            }

            if (!$selectedDepartment instanceof Department || $selectedDepartment->isIsActive() !== true) {
                $form->get('department')->addError(new FormError('Please select an active department.'));
                $hasErrors = true;
            }

            if (
                $selectedCollege instanceof College
                && $selectedDepartment instanceof Department
                && $selectedDepartment->getCollege()?->getId() !== $selectedCollege->getId()
            ) {
                $form->get('department')->addError(new FormError('The selected department does not belong to the selected college.'));
                $hasErrors = true;
            }

            if (!$hasErrors) {
                try {
                    $user = $registrationService->register([
                        'email' => $email,
                        'studentId' => $studentId,
                        'firstName' => $firstName,
                        'middleName' => $middleName !== '' ? $middleName : null,
                        'lastName' => $lastName,
                        'plainPassword' => $plainPassword,
                        'yearGraduated' => $batchYear,
                        'college' => $selectedCollege->getName(),
                        'course' => $selectedDepartment->getCode(),
                        'degreeProgram' => $selectedDepartment->getName(),
                        'dpaConsent' => (bool) $form->get('dataPrivacyConsent')->getData(),
                    ], 'active');

                    $this->addFlash('success', sprintf('Registration completed for batch %d. Welcome to the Alumni Tracker.', $batchYear));

                    try {
                        $response = $security->login($user, 'form_login', 'main');

                        if ($response instanceof Response) {
                            return $response;
                        }
                    } catch (\Throwable $exception) {
                        $this->addFlash('warning', 'Your account was created, but automatic sign-in was unavailable. Please log in manually.');
                        return $this->redirectToRoute('app_login');
                    }

                    return $this->redirectToRoute('app_home');
                } catch (RegistrationValidationException $exception) {
                    foreach ($exception->getFieldErrors() as $field => $message) {
                        if ($field === 'form' || !$form->has($field)) {
                            $form->addError(new FormError($message));

                            continue;
                        }

                        $form->get($field)->addError(new FormError($message));
                    }
                }
            }
        }

        return $this->render('registration/qr_register.html.twig', [
            'batchYear' => $batchYear,
            'registrationForm' => $form,
            'hasColleges' => count($colleges) > 0,
        ]);
    }

    private function ensureBatchTableExists(EntityManagerInterface $entityManager): void
    {
        $connection = $entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();

        if ($schemaManager->tablesExist(['qr_registration_batch'])) {
            return;
        }

        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS qr_registration_batch (
                id INT AUTO_INCREMENT NOT NULL,
                batch_year SMALLINT NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_qr_registration_batch_year (batch_year),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }
}