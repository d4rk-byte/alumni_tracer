<?php

namespace App\Controller;

use App\Entity\GtsSurvey;
use App\Entity\GtsSurveyTemplate;
use App\Entity\User;
use App\Form\GtsSurveyType;
use App\Repository\GtsSurveyQuestionRepository;
use App\Repository\GtsSurveyRepository;
use App\Service\GtsSurveyQuestionBank;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class SurveyController extends AbstractController
{
    #[Route('/admin/surveys/analytics', name: 'admin_survey_analytics', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminIndex(GtsSurveyRepository $surveyRepository): Response
    {
        $surveys = $surveyRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/survey_analytics.html.twig', [
            'surveys' => $surveys,
            'totalResponses' => count($surveys),
        ]);
    }

    #[Route('/admin/survey/preview/{id}', name: 'admin_survey_preview', methods: ['GET'], requirements: ['id' => '\\d+'])]
    #[Route('/staff/survey/preview/{id}', name: 'staff_survey_preview', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function preview(
        int $id,
        GtsSurveyRepository $surveyRepository,
        GtsSurveyQuestionRepository $questionRepository,
        GtsSurveyQuestionBank $questionBank,
    ): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_STAFF')) {
            throw $this->createAccessDeniedException('Survey preview is available to staff and admin accounts only.');
        }

        $survey = $id === 0 ? $this->createMockSurvey() : $surveyRepository->find($id);

        if (!$survey instanceof GtsSurvey) {
            throw $this->createNotFoundException('Survey preview data not found.');
        }

        $runtimeQuestions = $questionBank->createRuntimeQuestions($questionRepository->findActiveOrdered());
        if ($questionBank->getStoredResponseItems($survey->getDynamicAnswers()) !== []) {
            $runtimeQuestions = $questionBank->createRuntimeQuestionsFromStoredResponses($survey->getDynamicAnswers());
        }

        $form = $this->createForm(GtsSurveyType::class, $survey, [
            'disabled' => true,
        ]);

        return $this->render('admin/survey_preview.html.twig', [
            'form' => $form,
            'survey' => $survey,
            'institutionCode' => $survey->getInstitutionCode(),
            'controlCode' => $survey->getControlCode(),
            'questionSections' => $questionBank->groupBySection($runtimeQuestions),
            'dynamicAnswers' => $questionBank->extractAnswerValues($survey->getDynamicAnswers()),
            'isPreviewMode' => true,
            'hasAlreadyResponded' => false,
        ]);
    }

    #[Route('/admin/gts/surveys/{id}/preview', name: 'admin_gts_survey_preview', methods: ['GET'], requirements: ['id' => '\\d+'])]
    #[Route('/staff/gts/surveys/{id}/preview', name: 'staff_gts_survey_preview', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function previewTemplate(
        GtsSurveyTemplate $surveyTemplate,
        GtsSurveyQuestionRepository $questionRepository,
        GtsSurveyQuestionBank $questionBank,
    ): Response
    {
        if (!$this->isGranted('ROLE_ADMIN') && !$this->isGranted('ROLE_STAFF')) {
            throw $this->createAccessDeniedException('Survey preview is available to staff and admin accounts only.');
        }

        $survey = $this->createMockSurvey();
        $runtimeQuestions = $questionBank->createRuntimeQuestions($questionRepository->findActiveOrderedByTemplate($surveyTemplate));

        $form = $this->createForm(GtsSurveyType::class, $survey, [
            'disabled' => true,
        ]);

        return $this->render('admin/survey_preview.html.twig', [
            'form' => $form,
            'survey' => $survey,
            'institutionCode' => $survey->getInstitutionCode(),
            'controlCode' => $survey->getControlCode(),
            'questionSections' => $questionBank->groupBySection($runtimeQuestions),
            'dynamicAnswers' => [],
            'isPreviewMode' => true,
            'hasAlreadyResponded' => false,
        ]);
    }

    #[Route('/survey/download-certificate', name: 'survey_download_certificate', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function downloadCertificate(GtsSurveyRepository $surveyRepository): Response
    {
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF')) {
            $this->addFlash('warning', 'Surveys are for Alumni accounts only.');

            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->redirectToRoute('app_home');
            }

            return $this->redirectToRoute('staff_dashboard');
        }

        $currentUser = $this->getUser();

        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('Login required.');
        }

        if (!$surveyRepository->hasUserSubmitted($currentUser)) {
            $this->addFlash('warning', 'Please complete the survey first before downloading your certificate.');
            return $this->redirectToRoute('gts_new');
        }

        $survey = $surveyRepository->findOneByUser($currentUser);
        if ($survey === null) {
            $this->addFlash('danger', 'Survey record not found.');
            return $this->redirectToRoute('app_dashboard');
        }

        $alumni = $currentUser->getAlumni();
        $fullName = trim((string) $currentUser->getFullName());
        if ($alumni !== null) {
            $fullName = trim($alumni->getFirstName() . ' ' . $alumni->getLastName());
        }

        $graduationDate = null;
        if ($alumni?->getDateGraduated() !== null) {
            $graduationDate = $alumni->getDateGraduated();
        } elseif ($alumni?->getYearGraduated() !== null) {
            $graduationDate = \DateTimeImmutable::createFromFormat('Y-m-d', $alumni->getYearGraduated() . '-01-01') ?: null;
        }

        $html = $this->renderView('survey/certificate.html.twig', [
            'fullName' => $fullName,
            'graduationDate' => $graduationDate,
            'surveyDate' => $survey->getCreatedAt(),
            'issuedAt' => new \DateTimeImmutable(),
        ]);

        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $dompdfCacheDir = $projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'dompdf';
        (new Filesystem())->mkdir($dompdfCacheDir);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', $dompdfCacheDir);
        $options->set('fontCache', $dompdfCacheDir);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $safeName = preg_replace('/[^A-Za-z0-9\-_]/', '-', $fullName) ?: 'alumni';
        $filename = sprintf('norsu-certificate-of-completion-%s.pdf', strtolower($safeName));

        $response = new Response($dompdf->output());
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    private function createMockSurvey(): GtsSurvey
    {
        $survey = new GtsSurvey();
        $survey->setName('Sample Alumni, Preview User');
        $survey->setEmailAddress('preview@norsu.edu.ph');
        $survey->setInstitutionCode('NORSU-GTS');
        $survey->setControlCode('PREVIEW-GTS');

        return $survey;
    }
}
