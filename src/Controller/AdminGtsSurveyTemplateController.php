<?php

namespace App\Controller;

use App\Entity\GtsSurveyTemplate;
use App\Form\Admin\GtsSurveyTemplateType;
use App\Repository\GtsSurveyTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminGtsSurveyTemplateController extends AbstractController
{
    #[Route('/admin/gts/surveys', name: 'admin_gts_surveys_index', methods: ['GET'])]
    #[Route('/staff/gts/surveys', name: 'staff_gts_surveys_index', methods: ['GET'])]
    public function index(GtsSurveyTemplateRepository $repository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $templates = $repository->findAllOrdered();

        return $this->render('admin/gts_surveys/index.html.twig', [
            'templates' => $templates,
        ]);
    }

    #[Route('/admin/gts/surveys/new', name: 'admin_gts_surveys_new', methods: ['GET', 'POST'])]
    #[Route('/staff/gts/surveys/new', name: 'staff_gts_surveys_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $template = new GtsSurveyTemplate();
        $form = $this->createForm(GtsSurveyTemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($template);
            $entityManager->flush();

            $this->addFlash('success', 'Survey created. You can now add questions to it.');

            return $this->redirectToRoute(
                $this->isGranted('ROLE_ADMIN') ? 'admin_gts_questions_index' : 'staff_gts_questions_index',
                ['templateId' => $template->getId()]
            );
        }

        return $this->render('admin/gts_surveys/form.html.twig', [
            'form' => $form,
            'surveyTemplate' => $template,
            'isEdit' => false,
        ]);
    }

    #[Route('/admin/gts/surveys/{id}/edit', name: 'admin_gts_surveys_edit', methods: ['GET', 'POST'])]
    #[Route('/staff/gts/surveys/{id}/edit', name: 'staff_gts_surveys_edit', methods: ['GET', 'POST'])]
    public function edit(GtsSurveyTemplate $surveyTemplate, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $form = $this->createForm(GtsSurveyTemplateType::class, $surveyTemplate);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Survey updated.');

            return $this->redirectToRoute($this->getSurveysIndexRoute());
        }

        return $this->render('admin/gts_surveys/form.html.twig', [
            'form' => $form,
            'surveyTemplate' => $surveyTemplate,
            'isEdit' => true,
        ]);
    }

    #[Route('/admin/gts/surveys/{id}/delete', name: 'admin_gts_surveys_delete', methods: ['POST'])]
    #[Route('/staff/gts/surveys/{id}/delete', name: 'staff_gts_surveys_delete', methods: ['POST'])]
    public function delete(GtsSurveyTemplate $surveyTemplate, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        if ($this->isCsrfTokenValid('delete_gts_survey_' . $surveyTemplate->getId(), (string) $request->request->get('_token'))) {
            $entityManager->remove($surveyTemplate);
            $entityManager->flush();
            $this->addFlash('success', 'Survey and all its questions have been deleted.');
        }

        return $this->redirectToRoute($this->getSurveysIndexRoute());
    }

    private function getSurveysIndexRoute(): string
    {
        return $this->isGranted('ROLE_ADMIN') ? 'admin_gts_surveys_index' : 'staff_gts_surveys_index';
    }
}
