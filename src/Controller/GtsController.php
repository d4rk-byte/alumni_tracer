<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\GtsSurvey;
use App\Entity\SurveyInvitation;
use App\Form\GtsSurveyType;
use App\Repository\GtsSurveyQuestionRepository;
use App\Repository\GtsSurveyRepository;
use App\Repository\SurveyInvitationRepository;
use App\Service\AuditLogger;
use App\Service\GtsSurveyQuestionBank;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/gts')]
class GtsController extends AbstractController
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Fill out the CHED Graduate Tracer Survey.
     */
    #[Route('/new', name: 'gts_new', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        GtsSurveyRepository $surveyRepository,
        GtsSurveyQuestionRepository $questionRepository,
        GtsSurveyQuestionBank $questionBank,
    ): Response
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

        // Access should be based on verified account status, not on whether an Alumni row is already linked.
        if ($currentUser->getAccountStatus() !== 'active') {
            $this->addFlash('danger', 'Only verified alumni accounts can submit the tracer survey.');
            return $this->redirectToRoute('app_profile');
        }

        if ($surveyRepository->hasUserSubmittedLegacy($currentUser)) {
            $this->addFlash('warning', 'You have already completed this survey.');
            return $this->redirectToRoute('app_dashboard');
        }

        $survey = new GtsSurvey();
        $survey->setUser($currentUser);

        // Pre-fill from logged-in user
        $user = $currentUser;
        $survey->setName($user->getLastName() . ', ' . $user->getFirstName());
        $survey->setEmailAddress($user->getEmail());
        $survey->setInstitutionCode($this->resolveInstitutionCode());
        $survey->setControlCode($this->generateControlCode($currentUser));

        $runtimeQuestions = $questionBank->createRuntimeQuestions($questionRepository->findActiveOrdered());

        $form = $this->createForm(GtsSurveyType::class, $survey);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleSubmission($request, $survey, $currentUser, $em, $runtimeQuestions, $questionBank);

            $this->audit->log('GTS Survey submitted', 'GtsSurvey', $survey->getId());

            $this->addFlash('success', 'Success: Your Graduate Tracer Survey was saved and your profile tracer status is now TRACED.');
            return $this->redirectToRoute('app_profile');
        }

        return $this->render('gts/new.html.twig', [
            'form' => $form,
            'survey' => $survey,
            'institutionCode' => $survey->getInstitutionCode(),
            'controlCode' => $survey->getControlCode(),
            'questionSections' => $questionBank->groupBySection($runtimeQuestions),
            'dynamicAnswers' => $request->request->all('dynamic_answers'),
            'hasAlreadyResponded' => false,
        ]);
    }

    #[Route('/invitations/{token}', name: 'gts_invitation_entry', methods: ['GET', 'POST'])]
    public function invitation(
        string $token,
        Request $request,
        EntityManagerInterface $em,
        GtsSurveyRepository $surveyRepository,
        SurveyInvitationRepository $invitationRepository,
        GtsSurveyQuestionRepository $questionRepository,
        GtsSurveyQuestionBank $questionBank,
    ): Response
    {
        $invitation = $invitationRepository->findByToken($token);
        if (!$invitation instanceof SurveyInvitation) {
            throw $this->createNotFoundException('Invitation not found.');
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            return $this->redirectToRoute('app_login', [
                '_target_path' => $request->getPathInfo(),
            ]);
        }

        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF')) {
            throw $this->createAccessDeniedException('Survey invitations are for alumni accounts only.');
        }

        if ($invitation->getUser()->getId() !== $currentUser->getId()) {
            throw $this->createAccessDeniedException('This survey invitation does not belong to your account.');
        }

        if ($currentUser->getAccountStatus() !== 'active') {
            $this->addFlash('danger', 'Only verified alumni accounts can submit the tracer survey.');
            return $this->redirectToRoute('app_profile');
        }

        if ($invitation->isExpired() || $invitation->getStatus() === SurveyInvitation::STATUS_EXPIRED) {
            if ($invitation->getStatus() !== SurveyInvitation::STATUS_EXPIRED) {
                $invitation->setStatus(SurveyInvitation::STATUS_EXPIRED);
                $em->persist($invitation);
                $em->flush();
            }
            $this->addFlash('warning', 'This survey invitation has expired.');
            return $this->redirectToRoute('app_dashboard');
        }

        if (
            $invitation->getStatus() === SurveyInvitation::STATUS_COMPLETED
            || $surveyRepository->hasUserSubmittedForInvitation($currentUser, $invitation)
        ) {
            $this->addFlash('info', 'You have already completed this survey invitation.');
            return $this->redirectToRoute('app_dashboard');
        }

        $template = $invitation->getCampaign()->getSurveyTemplate();
        $runtimeQuestions = $questionBank->createRuntimeQuestions($questionRepository->findActiveOrderedByTemplate($template));
        if (count($runtimeQuestions) === 0) {
            $this->addFlash('warning', 'This survey template has no active questions yet.');
            return $this->redirectToRoute('app_dashboard');
        }

        if ($invitation->getOpenedAt() === null) {
            $invitation->setOpenedAt(new \DateTimeImmutable());
            if (
                $invitation->getStatus() === SurveyInvitation::STATUS_QUEUED
                || $invitation->getStatus() === SurveyInvitation::STATUS_SENT
            ) {
                $invitation->setStatus(SurveyInvitation::STATUS_OPENED);
            }
            $em->persist($invitation);
            $em->flush();
        }

        $survey = new GtsSurvey();
        $survey->setUser($currentUser);
        $survey->setSurveyTemplate($template);
        $survey->setSurveyInvitation($invitation);
        $survey->setName($currentUser->getLastName() . ', ' . $currentUser->getFirstName());
        $survey->setEmailAddress($currentUser->getEmail());
        $survey->setInstitutionCode($this->resolveInstitutionCode());
        $survey->setControlCode($this->generateControlCode($currentUser));

        $form = $this->createForm(GtsSurveyType::class, $survey);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleSubmission($request, $survey, $currentUser, $em, $runtimeQuestions, $questionBank, $invitation);

            $this->audit->log('GTS Survey invitation submitted', 'GtsSurvey', $survey->getId());
            $this->addFlash('success', 'Success: Your Graduate Tracer Survey was submitted.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('gts/new.html.twig', [
            'form' => $form,
            'survey' => $survey,
            'institutionCode' => $survey->getInstitutionCode(),
            'controlCode' => $survey->getControlCode(),
            'questionSections' => $questionBank->groupBySection($runtimeQuestions),
            'dynamicAnswers' => $request->request->all('dynamic_answers'),
            'hasAlreadyResponded' => false,
        ]);
    }

    /**
     * Thank-you page after successful submission.
     */
    #[Route('/thank-you', name: 'gts_thankyou', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function thankYou(): Response
    {
        return $this->render('gts/thank_you.html.twig');
    }

    /**
     * Admin/Staff: view all submitted surveys.
     */
    #[Route('/', name: 'gts_index', methods: ['GET'])]
    #[IsGranted('ROLE_STAFF')]
    public function index(Request $request, GtsSurveyRepository $repo, GtsSurveyQuestionBank $questionBank): Response
    {
        $page  = max(1, $request->query->getInt('page', 1));
        $limit = 20;

        $qb = $repo->createQueryBuilder('s')->orderBy('s.createdAt', 'DESC');
        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        $paginator = new Paginator($qb);
        $totalItems = count($paginator);
        $totalPages = (int) ceil($totalItems / $limit);
        $surveyRows = [];
        foreach ($paginator as $survey) {
            if (!$survey instanceof GtsSurvey) {
                continue;
            }

            $surveyRows[] = [
                'survey' => $survey,
                'summary' => $questionBank->extractListSummary($survey),
            ];
        }

        return $this->render('gts/index.html.twig', [
            'surveyRows' => $surveyRows,
            'totalItems' => $totalItems,
            'currentPage' => $page,
            'totalPages'  => $totalPages,
        ]);
    }

    /**
     * View a single survey response.
     */
    #[Route('/{id}', name: 'gts_show', methods: ['GET'])]
    #[IsGranted('ROLE_STAFF')]
    public function show(GtsSurvey $survey, GtsSurveyQuestionBank $questionBank): Response
    {
        return $this->render('gts/show.html.twig', [
            'survey' => $survey,
            'dynamicResponseSections' => $questionBank->groupBySection($questionBank->getStoredResponseItems($survey->getDynamicAnswers())),
        ]);
    }

    /**
     * Submission Bridge: map request data into survey JSON payloads,
     * then set linked alumni tracer status and submission timestamp.
     */
    private function handleSubmission(
        Request $request,
        GtsSurvey $survey,
        User $user,
        EntityManagerInterface $em,
        array $runtimeQuestions,
        GtsSurveyQuestionBank $questionBank,
        ?SurveyInvitation $invitation = null,
    ): void
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ROLE_STAFF', $user->getRoles(), true)) {
            throw $this->createAccessDeniedException('Surveys are for Alumni accounts only.');
        }

        $survey->setInstitutionCode($this->resolveInstitutionCode());
        $survey->setControlCode($survey->getControlCode() ?: $this->generateControlCode($user));
        $survey->setDynamicAnswers($questionBank->createResponseSnapshot($request->request->all('dynamic_answers'), $runtimeQuestions));

        $alumni = $user->getAlumni();
        if ($alumni !== null) {
            $alumni->setTracerStatus('TRACED');
            $alumni->setLastTracerSubmissionAt(new \DateTime());
        }

        if ($invitation instanceof SurveyInvitation) {
            $invitation->setCompletedAt(new \DateTimeImmutable());
            if ($invitation->getOpenedAt() === null) {
                $invitation->setOpenedAt(new \DateTimeImmutable());
            }
            $invitation->setStatus(SurveyInvitation::STATUS_COMPLETED);
            $em->persist($invitation);
        }

        $em->persist($survey);
        $em->flush();
    }

    private function resolveInstitutionCode(): string
    {
        $value = (string) ($_ENV['GTS_INSTITUTION_CODE'] ?? $_SERVER['GTS_INSTITUTION_CODE'] ?? 'NORSU-GTS');

        return trim($value) !== '' ? trim($value) : 'NORSU-GTS';
    }

    private function generateControlCode(User $user): string
    {
        $prefix = (string) ($_ENV['GTS_CONTROL_CODE_PREFIX'] ?? $_SERVER['GTS_CONTROL_CODE_PREFIX'] ?? 'GTS');
        $prefix = trim($prefix) !== '' ? trim($prefix) : 'GTS';

        return sprintf('%s-%s-%d-%s', $prefix, date('Ymd'), (int) $user->getId(), strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
    }

}
