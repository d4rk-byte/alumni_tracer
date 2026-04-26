<?php

namespace App\Controller;

use App\Entity\GtsSurveyQuestion;
use App\Entity\GtsSurveyTemplate;
use App\Form\Admin\GtsSurveyQuestionType;
use App\Repository\GtsSurveyQuestionRepository;
use App\Service\GtsSurveyQuestionBank;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminGtsSurveyQuestionController extends AbstractController
{
    #[Route('/admin/gts/surveys/{templateId}/questions', name: 'admin_gts_questions_index', methods: ['GET'])]
    #[Route('/staff/gts/surveys/{templateId}/questions', name: 'staff_gts_questions_index', methods: ['GET'])]
    public function index(int $templateId, GtsSurveyQuestionRepository $repository, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $template = $entityManager->find(GtsSurveyTemplate::class, $templateId);
        if (!$template instanceof GtsSurveyTemplate) {
            throw $this->createNotFoundException('Survey not found.');
        }

        $questions = $repository->findOrderedByTemplate($template);
        $questionSections = [];
        $activeQuestionCount = 0;

        foreach ($questions as $question) {
            $sectionName = trim($question->getSection()) !== '' ? trim($question->getSection()) : 'Questionnaire';

            if (!isset($questionSections[$sectionName])) {
                $questionSections[$sectionName] = [
                    'title' => $sectionName,
                    'questions' => [],
                ];
            }

            $questionSections[$sectionName]['questions'][] = $question;

            if ($question->isActive()) {
                ++$activeQuestionCount;
            }
        }

        return $this->render('admin/gts_questions/index.html.twig', [
            'surveyTemplate' => $template,
            'questions' => $questions,
            'questionSections' => array_values($questionSections),
            'activeQuestionCount' => $activeQuestionCount,
            'sectionCount' => count($questionSections),
        ]);
    }

    #[Route('/admin/gts/surveys/{templateId}/questions/new', name: 'admin_gts_questions_new', methods: ['GET', 'POST'])]
    #[Route('/staff/gts/surveys/{templateId}/questions/new', name: 'staff_gts_questions_new', methods: ['GET', 'POST'])]
    public function create(int $templateId, Request $request, EntityManagerInterface $entityManager, GtsSurveyQuestionBank $questionBank): Response
    {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $template = $entityManager->find(GtsSurveyTemplate::class, $templateId);
        if (!$template instanceof GtsSurveyTemplate) {
            throw $this->createNotFoundException('Survey not found.');
        }

        $question = new GtsSurveyQuestion();
        $question->setSurveyTemplate($template);

        $form = $this->createForm(GtsSurveyQuestionType::class, $question);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $question->setOptions($questionBank->parseOptionsCsv((string) $question->getInputType(), (string) $form->get('optionsCsv')->getData()));
            $entityManager->persist($question);
            $entityManager->flush();

            $this->addFlash('success', 'Survey question created.');

            return $this->redirectToRoute($this->getQuestionIndexRoute(), ['templateId' => $template->getId()]);
        }

        return $this->render('admin/gts_questions/form.html.twig', [
            'form' => $form,
            'question' => $question,
            'surveyTemplate' => $template,
            'isEdit' => false,
        ]);
    }

    #[Route('/admin/gts/questions/{id}/edit', name: 'admin_gts_questions_edit', methods: ['GET', 'POST'])]
    #[Route('/staff/gts/questions/{id}/edit', name: 'staff_gts_questions_edit', methods: ['GET', 'POST'])]
    public function edit(GtsSurveyQuestion $question, Request $request, EntityManagerInterface $entityManager, GtsSurveyQuestionBank $questionBank): Response
    {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $template = $question->getSurveyTemplate();

        $form = $this->createForm(GtsSurveyQuestionType::class, $question);
        $form->get('optionsCsv')->setData($questionBank->optionsToCsv($question->getInputType(), $question->getOptions()));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $question->setOptions($questionBank->parseOptionsCsv((string) $question->getInputType(), (string) $form->get('optionsCsv')->getData()));
            $entityManager->flush();

            $this->addFlash('success', 'Survey question updated.');

            if ($template instanceof GtsSurveyTemplate) {
                return $this->redirectToRoute($this->getQuestionIndexRoute(), ['templateId' => $template->getId()]);
            }

            return $this->redirectToRoute($this->getSurveysIndexRoute());
        }

        return $this->render('admin/gts_questions/form.html.twig', [
            'form' => $form,
            'question' => $question,
            'surveyTemplate' => $template,
            'isEdit' => true,
        ]);
    }

    #[Route('/admin/gts/questions/{id}/delete', name: 'admin_gts_questions_delete', methods: ['POST'])]
    #[Route('/staff/gts/questions/{id}/delete', name: 'staff_gts_questions_delete', methods: ['POST'])]
    public function delete(GtsSurveyQuestion $question, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $template = $question->getSurveyTemplate();

        if ($this->isCsrfTokenValid('delete_gts_question_' . $question->getId(), (string) $request->request->get('_token'))) {
            $entityManager->remove($question);
            $entityManager->flush();
            $this->addFlash('success', 'Survey question removed.');
        }

        if ($template instanceof GtsSurveyTemplate) {
            return $this->redirectToRoute($this->getQuestionIndexRoute(), ['templateId' => $template->getId()]);
        }

        return $this->redirectToRoute($this->getSurveysIndexRoute());
    }

    #[Route('/admin/gts/surveys/{templateId}/questions/import-defaults', name: 'admin_gts_questions_import_defaults', methods: ['POST'])]
    #[Route('/staff/gts/surveys/{templateId}/questions/import-defaults', name: 'staff_gts_questions_import_defaults', methods: ['POST'])]
    public function importDefaults(
        int $templateId,
        Request $request,
        EntityManagerInterface $entityManager,
        GtsSurveyQuestionRepository $repository,
        GtsSurveyQuestionBank $questionBank,
    ): Response
    {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $template = $entityManager->find(GtsSurveyTemplate::class, $templateId);
        if (!$template instanceof GtsSurveyTemplate) {
            throw $this->createNotFoundException('Survey not found.');
        }

        if (!$this->isCsrfTokenValid('import_gts_defaults', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Unable to import the default questionnaire.');

            return $this->redirectToRoute($this->getQuestionIndexRoute(), ['templateId' => $templateId]);
        }

        if (count($repository->findOrderedByTemplate($template)) > 0) {
            $this->addFlash('warning', 'Default questionnaire import is only available when this survey has no questions yet.');

            return $this->redirectToRoute($this->getQuestionIndexRoute(), ['templateId' => $templateId]);
        }

        $count = $questionBank->importDefaults($entityManager, $template);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Imported %d default survey questions.', $count));

        return $this->redirectToRoute($this->getQuestionIndexRoute(), ['templateId' => $templateId]);
    }

    private function getQuestionIndexRoute(): string
    {
        return $this->isGranted('ROLE_ADMIN') ? 'admin_gts_questions_index' : 'staff_gts_questions_index';
    }

    private function getSurveysIndexRoute(): string
    {
        return $this->isGranted('ROLE_ADMIN') ? 'admin_gts_surveys_index' : 'staff_gts_surveys_index';
    }
}
