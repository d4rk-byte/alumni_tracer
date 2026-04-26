<?php

namespace App\Controller;

use App\Entity\Alumni;
use App\Entity\AuditLog;
use App\Repository\AlumniRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/reports')]
#[IsGranted('ROLE_STAFF')]
class ReportController extends AbstractController
{
    public function __construct(private AuditLogger $audit) {}

    #[Route('/', name: 'report_index', methods: ['GET'])]
    public function index(AlumniRepository $repo): Response
    {
        $totalAlumni = $repo->count([]);
        $employed = $repo->count(['employmentStatus' => 'Employed']);
        $selfEmployed = $repo->count(['employmentStatus' => 'Self-Employed']);
        $unemployed = $repo->count(['employmentStatus' => 'Unemployed']);

        // Employment Rate by Course
        $courseEmployment = $repo->createQueryBuilder('a')
            ->select('a.course, COUNT(a.id) AS total,
                      SUM(CASE WHEN a.employmentStatus = :emp OR a.employmentStatus = :self THEN 1 ELSE 0 END) AS employedCount')
            ->setParameter('emp', 'Employed')
            ->setParameter('self', 'Self-Employed')
            ->groupBy('a.course')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();

        // Salary Distribution
        $salaryDistribution = $repo->createQueryBuilder('a')
            ->select('a.monthlySalary, COUNT(a.id) AS total')
            ->where('a.monthlySalary IS NOT NULL')
            ->groupBy('a.monthlySalary')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();

        // Local vs Abroad
        $workAbroad = $repo->count(['workAbroad' => true]);
        $workLocal = $totalAlumni - $workAbroad;

        // Year distribution
        $yearDistribution = $repo->createQueryBuilder('a')
            ->select('a.yearGraduated, COUNT(a.id) AS total')
            ->where('a.yearGraduated IS NOT NULL')
            ->groupBy('a.yearGraduated')
            ->orderBy('a.yearGraduated', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        // Job Related to Course
        $jobRelated = $repo->count(['jobRelatedToCourse' => true]);
        $jobNotRelated = $repo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.jobRelatedToCourse = false')
            ->getQuery()
            ->getSingleScalarResult();

        // Province Distribution
        $provinceDistribution = $repo->createQueryBuilder('a')
            ->select('a.province, COUNT(a.id) AS total')
            ->where('a.province IS NOT NULL AND a.province != :empty')
            ->setParameter('empty', '')
            ->groupBy('a.province')
            ->orderBy('total', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $this->render('report/index.html.twig', [
            'totalAlumni' => $totalAlumni,
            'employed' => $employed,
            'selfEmployed' => $selfEmployed,
            'unemployed' => $unemployed,
            'employmentRate' => $totalAlumni > 0 ? round(($employed + $selfEmployed) / $totalAlumni * 100, 1) : 0,
            'courseStats' => array_map(function ($row) {
                $rate = $row['total'] > 0 ? round($row['employedCount'] / $row['total'] * 100, 1) : 0;
                return ['name' => $row['course'] ?? 'Unknown', 'total' => $row['total'], 'employed' => $row['employedCount'], 'rate' => $rate];
            }, $courseEmployment),
            'salaryStats' => array_map(function ($row) use ($totalAlumni) {
                return ['range' => $row['monthlySalary'] ?? 'N/A', 'count' => $row['total'], 'percent' => $totalAlumni > 0 ? round($row['total'] / $totalAlumni * 100, 1) : 0];
            }, $salaryDistribution),
            'workAbroad' => $workAbroad,
            'workLocal' => $workLocal,
            'yearStats' => array_map(function ($row) use ($totalAlumni) {
                return ['year' => $row['yearGraduated'], 'count' => $row['total'], 'percent' => $totalAlumni > 0 ? round($row['total'] / $totalAlumni * 100, 1) : 0];
            }, $yearDistribution),
            'relevanceStats' => [
                ['label' => 'Related', 'count' => $jobRelated, 'percent' => $totalAlumni > 0 ? round($jobRelated / $totalAlumni * 100, 1) : 0],
                ['label' => 'Not Related', 'count' => $jobNotRelated, 'percent' => $totalAlumni > 0 ? round($jobNotRelated / $totalAlumni * 100, 1) : 0],
            ],
            'provinceStats' => array_map(function ($row) use ($totalAlumni) {
                return ['province' => $row['province'], 'count' => $row['total'], 'percent' => $totalAlumni > 0 ? round($row['total'] / $totalAlumni * 100, 1) : 0];
            }, $provinceDistribution),
        ]);
    }

    /**
     * Export alumni data as CSV for reporting (supports filters).
     */
    #[Route('/export', name: 'report_export', methods: ['GET'])]
    public function export(Request $request, AlumniRepository $repo): StreamedResponse
    {
        $qb = $repo->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->andWhere('a.deletedAt IS NULL')
            ->orderBy('a.lastName', 'ASC');

        $course = $request->query->get('course', '');
        $year = $request->query->get('year', '');
        $campus = $request->query->get('campus', '');
        $status = $request->query->get('status', '');
        $province = $request->query->get('province', '');
        $registrationState = $request->query->get('registration_state', '');

        if ($course !== '') {
            $qb->andWhere('a.course = :course')->setParameter('course', $course);
        }
        if ($year !== '') {
            $qb->andWhere('a.yearGraduated = :year')->setParameter('year', (int) $year);
        }
        if ($campus !== '') {
            $qb->andWhere('a.college LIKE :campus')->setParameter('campus', '%' . trim((string) $campus) . '%');
        }
        if ($status !== '') {
            $qb->andWhere('a.employmentStatus = :status')->setParameter('status', $status);
        }
        if ($province !== '') {
            $qb->andWhere('a.province = :province')->setParameter('province', $province);
        }
        if ($registrationState !== '') {
            $repo->applyRegistrationStateFilter($qb, 'u', $registrationState);
        }

        $alumni = $qb->getQuery()->getResult();

        $filterDesc = [];
        if ($course) $filterDesc[] = "course={$course}";
        if ($year) $filterDesc[] = "year={$year}";
        if ($campus) $filterDesc[] = "campus={$campus}";
        if ($status) $filterDesc[] = "status={$status}";
        if ($province) $filterDesc[] = "province={$province}";
        if ($registrationState) $filterDesc[] = "registration_state={$registrationState}";
        $filterStr = $filterDesc ? ' (filters: ' . implode(', ', $filterDesc) . ')' : '';

        $this->audit->log(
            AuditLog::ACTION_EXPORT_REPORT,
            'AlumniReport',
            null,
            'Exported alumni data report (CSV) — ' . count($alumni) . ' records' . $filterStr
        );

        $filename = 'Alumni_Report_' . date('Y-m-d') . '.csv';

        $response = new StreamedResponse(function () use ($alumni) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Student Number', 'Last Name', 'First Name', 'Middle Name', 'Suffix',
                'Sex', 'Civil Status', 'Email', 'Contact Number',
                'Province', 'Course', 'Year Graduated', 'College',
                'Employment Status', 'Employment Type', 'Company', 'Job Title',
                'Industry', 'Monthly Salary', 'Job Related to Course',
                'Work Abroad', 'Country'
            ]);

            foreach ($alumni as $a) {
                fputcsv($handle, [
                    $a->getStudentNumber(),
                    $a->getLastName(),
                    $a->getFirstName(),
                    $a->getMiddleName() ?? '',
                    $a->getSuffix() ?? '',
                    $a->getSex() ?? '',
                    $a->getCivilStatus() ?? '',
                    $a->getEmailAddress(),
                    $a->getContactNumber() ?? '',
                    $a->getProvince() ?? '',
                    $a->getCourse() ?? '',
                    $a->getYearGraduated() ?? '',
                    $a->getCollege() ?? '',
                    $a->getEmploymentStatus() ?? '',
                    $a->getEmploymentType() ?? '',
                    $a->getCompanyName() ?? '',
                    $a->getJobTitle() ?? '',
                    $a->getIndustry() ?? '',
                    $a->getMonthlySalary() ?? '',
                    $a->isJobRelatedToCourse() === null ? '' : ($a->isJobRelatedToCourse() ? 'Yes' : 'No'),
                    $a->isWorkAbroad() === null ? '' : ($a->isWorkAbroad() ? 'Yes' : 'No'),
                    $a->getCountryOfEmployment() ?? '',
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    private const CSV_HEADERS = [
        'Student Number', 'Last Name', 'First Name', 'Middle Name', 'Suffix',
        'Sex', 'Civil Status', 'Email', 'Contact Number',
        'Province', 'Course', 'Year Graduated', 'College',
        'Employment Status', 'Employment Type', 'Company', 'Job Title',
        'Industry', 'Monthly Salary', 'Job Related to Course',
        'Work Abroad', 'Country',
    ];

    #[Route('/import', name: 'report_import', methods: ['GET', 'POST'])]
    public function import(Request $request, AlumniRepository $repo, EntityManagerInterface $em): Response
    {
        $results = null;

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('import_alumni', $token)) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('report_import');
            }

            $file = $request->files->get('csv_file');
            if (!$file instanceof UploadedFile) {
                $this->addFlash('error', 'Please upload a CSV file.');
                return $this->redirectToRoute('report_import');
            }

            if (!$file->isValid()) {
                $this->addFlash('error', $this->getUploadErrorMessage($file->getError()));
                return $this->redirectToRoute('report_import');
            }

            $ext = strtolower($file->getClientOriginalExtension());
            $mimeType = strtolower((string) $file->getClientMimeType());
            $allowedMimeTypes = [
                'text/csv',
                'text/plain',
                'application/csv',
                'application/vnd.ms-excel',
                'text/comma-separated-values',
            ];

            if ($ext !== 'csv' && !in_array($mimeType, $allowedMimeTypes, true)) {
                $this->addFlash('error', 'Only CSV files are allowed.');
                return $this->redirectToRoute('report_import');
            }

            if ($file->getSize() > 5 * 1024 * 1024) {
                $this->addFlash('error', 'File size must be under 5 MB.');
                return $this->redirectToRoute('report_import');
            }

            $results = $this->processImport($file->getPathname(), $repo, $em);
        }

        return $this->render('report/import.html.twig', [
            'results' => $results,
        ]);
    }

    #[Route('/import/template', name: 'report_import_template', methods: ['GET'])]
    public function importTemplate(): StreamedResponse
    {
        $response = new StreamedResponse(function () {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::CSV_HEADERS);
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="Alumni_Import_Template.csv"');

        return $response;
    }

    private function processImport(string $filePath, AlumniRepository $repo, EntityManagerInterface $em): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Could not open the uploaded file.']];
        }

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") {
                fclose($handle);
                return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Unsupported CSV encoding. Please save/export the file as UTF-8 CSV and try again.']];
            }

            rewind($handle);
        }

        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['CSV file is empty or has no header row.']];
        }

        $delimiter = $this->detectCsvDelimiter($headerLine);
        $headerRow = str_getcsv($headerLine, $delimiter);
        if (!$headerRow) {
            fclose($handle);
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['CSV file is empty or has no header row.']];
        }

        $headerRow = array_map(fn ($header) => $this->normalizeHeader((string) $header), $headerRow);

        $required = ['Student Number', 'Last Name', 'First Name', 'Email'];
        $requiredNormalized = array_map(fn ($header) => $this->normalizeHeader($header), $required);
        $missing = array_diff($required, $headerRow);

        if ($missing) {
            $missingNormalized = array_diff($requiredNormalized, $headerRow);
            if (!$missingNormalized) {
                $missing = [];
            }
        }

        if ($missing) {
            fclose($handle);
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Missing required columns: ' . implode(', ', $missing)]];
        }

        // Build column index map
        $colMap = array_flip($headerRow);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $row = 1; // header was row 1

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row++;

            if (count($data) < count($required)) {
                $errors[] = "Row {$row}: insufficient columns, skipped.";
                $skipped++;
                continue;
            }

            $studentNumber = trim($data[$colMap['Student Number']] ?? '');
            $lastName      = trim($data[$colMap['Last Name']] ?? '');
            $firstName     = trim($data[$colMap['First Name']] ?? '');
            $email         = trim($data[$colMap['Email']] ?? '');

            if ($studentNumber === '' || $lastName === '' || $firstName === '' || $email === '') {
                $errors[] = "Row {$row}: missing required field (Student Number, Last Name, First Name, or Email), skipped.";
                $skipped++;
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$row}: invalid email '{$email}', skipped.";
                $skipped++;
                continue;
            }

            $alumni = $repo->findOneBy(['studentNumber' => $studentNumber]);
            $isNew = false;

            if (!$alumni) {
                $existingEmail = $repo->findOneBy(['emailAddress' => $email]);
                if ($existingEmail) {
                    $errors[] = "Row {$row}: email '{$email}' already belongs to another alumni record, skipped.";
                    $skipped++;
                    continue;
                }
                $alumni = new Alumni();
                $isNew = true;
            }

            $alumni->setStudentNumber($studentNumber);
            $alumni->setLastName($lastName);
            $alumni->setFirstName($firstName);
            $alumni->setEmailAddress($email);

            if (isset($colMap['Sex']) && isset($data[$colMap['Sex']])) {
                $sex = trim($data[$colMap['Sex']]);
                if ($sex !== '' && !in_array($sex, ['Male', 'Female'], true)) {
                    $errors[] = "Row {$row}: invalid Sex '{$sex}' (must be Male or Female), skipped.";
                    $skipped++;
                    continue;
                }
            }

            $validStatuses = ['Employed', 'Unemployed', 'Self-Employed', 'Freelance', 'Part-Time'];
            if (isset($colMap['Employment Status']) && isset($data[$colMap['Employment Status']])) {
                $empStatus = trim($data[$colMap['Employment Status']]);
                if ($empStatus !== '' && !in_array($empStatus, $validStatuses, true)) {
                    $errors[] = "Row {$row}: invalid Employment Status '{$empStatus}' (valid: " . implode(', ', $validStatuses) . "), skipped.";
                    $skipped++;
                    continue;
                }
            }

            if (isset($colMap['Year Graduated']) && isset($data[$colMap['Year Graduated']])) {
                $yrVal = trim($data[$colMap['Year Graduated']]);
                if ($yrVal !== '') {
                    if (!ctype_digit($yrVal) || (int) $yrVal < 1950 || (int) $yrVal > (int) date('Y') + 1) {
                        $errors[] = "Row {$row}: invalid Year Graduated '{$yrVal}' (must be 1950–" . ((int) date('Y') + 1) . "), skipped.";
                        $skipped++;
                        continue;
                    }
                }
            }

            $this->setOptional($alumni, $data, $colMap, 'Middle Name', 'setMiddleName');
            $this->setOptional($alumni, $data, $colMap, 'Suffix', 'setSuffix');
            $this->setOptional($alumni, $data, $colMap, 'Sex', 'setSex');
            $this->setOptional($alumni, $data, $colMap, 'Civil Status', 'setCivilStatus');
            $this->setOptional($alumni, $data, $colMap, 'Contact Number', 'setContactNumber');
            $this->setOptional($alumni, $data, $colMap, 'Province', 'setProvince');
            $this->setOptional($alumni, $data, $colMap, 'Course', 'setCourse');
            $this->setOptional($alumni, $data, $colMap, 'College', 'setCollege');
            $this->setOptional($alumni, $data, $colMap, 'Employment Status', 'setEmploymentStatus');
            $this->setOptional($alumni, $data, $colMap, 'Employment Type', 'setEmploymentType');
            $this->setOptional($alumni, $data, $colMap, 'Company', 'setCompanyName');
            $this->setOptional($alumni, $data, $colMap, 'Job Title', 'setJobTitle');
            $this->setOptional($alumni, $data, $colMap, 'Industry', 'setIndustry');
            $this->setOptional($alumni, $data, $colMap, 'Monthly Salary', 'setMonthlySalary');
            $this->setOptional($alumni, $data, $colMap, 'Country', 'setCountryOfEmployment');

            if (isset($colMap['Year Graduated']) && isset($data[$colMap['Year Graduated']])) {
                $yr = trim($data[$colMap['Year Graduated']]);
                if ($yr !== '' && ctype_digit($yr)) {
                    $alumni->setYearGraduated((int) $yr);
                }
            }

            if (isset($colMap['Job Related to Course']) && isset($data[$colMap['Job Related to Course']])) {
                $val = strtolower(trim($data[$colMap['Job Related to Course']]));
                if ($val !== '') {
                    $alumni->setJobRelatedToCourse(in_array($val, ['yes', '1', 'true'], true));
                }
            }

            if (isset($colMap['Work Abroad']) && isset($data[$colMap['Work Abroad']])) {
                $val = strtolower(trim($data[$colMap['Work Abroad']]));
                if ($val !== '') {
                    $alumni->setWorkAbroad(in_array($val, ['yes', '1', 'true'], true));
                }
            }

            $em->persist($alumni);

            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }

            if (($created + $updated) % 50 === 0) {
                $em->flush();
            }
        }

        fclose($handle);
        $em->flush();

        $this->audit->log(
            AuditLog::ACTION_IMPORT_ALUMNI,
            'AlumniImport',
            null,
            "Imported alumni CSV — {$created} created, {$updated} updated, {$skipped} skipped"
        );

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }

    private function setOptional(Alumni $alumni, array $data, array $colMap, string $column, string $setter): void
    {
        if (isset($colMap[$column]) && isset($data[$colMap[$column]])) {
            $val = trim($data[$colMap[$column]]);
            if ($val !== '') {
                $alumni->$setter($val);
            }
        }
    }

    private function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large. Please upload a CSV under 5 MB.',
            UPLOAD_ERR_PARTIAL => 'The file upload was incomplete. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded. Please select a CSV file.',
            UPLOAD_ERR_NO_TMP_DIR => 'Upload failed: temporary folder is missing on the server.',
            UPLOAD_ERR_CANT_WRITE => 'Upload failed: the server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'Upload failed: a PHP extension stopped the upload.',
            default => 'Upload failed. Please try again with a valid CSV file.',
        };
    }

    private function detectCsvDelimiter(string $headerLine): string
    {
        $delimiters = [',', ';', "\t", '|'];
        $best = ',';
        $maxColumns = 0;

        foreach ($delimiters as $candidate) {
            $columns = str_getcsv($headerLine, $candidate);
            $count = is_array($columns) ? count($columns) : 0;
            if ($count > $maxColumns) {
                $maxColumns = $count;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/u', '', $header) ?? $header;
        $header = str_replace("\xC2\xA0", ' ', $header);
        $header = trim($header);

        return preg_replace('/\s+/', ' ', $header) ?? $header;
    }
}
