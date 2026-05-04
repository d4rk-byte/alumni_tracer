<?php

namespace App\Controller;

use App\Entity\GtsSurvey;
use App\Entity\Notification;
use App\Entity\SurveyInvitation;
use App\Entity\User;
use App\Repository\GtsSurveyQuestionRepository;
use App\Repository\GtsSurveyRepository;
use App\Repository\SurveyInvitationRepository;
use App\Service\AuditLogger;
use App\Service\GtsSurveyQuestionBank;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/account/survey/invitations')]
#[IsGranted('ROLE_USER')]
class SurveyInvitationApiController extends AbstractController
{
    public function __construct(private AuditLogger $audit, private NotificationService $notifications)
    {
    }

    #[Route('', name: 'api_account_survey_invitations_index', methods: ['GET'])]
    public function index(SurveyInvitationRepository $invitationRepository): JsonResponse
    {
        $items = array_map(
            fn (SurveyInvitation $invitation): array => $this->serializeInvitationSummary($invitation),
            $invitationRepository->findRecentForUser($this->currentUser(), 50)
        );

        return $this->json(['items' => $items]);
    }

    #[Route('/{token}', name: 'api_account_survey_invitation_show', methods: ['GET'])]
    public function show(
        string $token,
        SurveyInvitationRepository $invitationRepository,
        GtsSurveyRepository $surveyRepository,
        GtsSurveyQuestionRepository $questionRepository,
        GtsSurveyQuestionBank $questionBank,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $context = $this->resolveInvitationContext(
            $token,
            $invitationRepository,
            $surveyRepository,
            $questionRepository,
            $questionBank,
            $entityManager,
            true,
        );

        if ($context instanceof JsonResponse) {
            return $context;
        }

        return $this->json(['item' => $context]);
    }

    #[Route('/{token}', name: 'api_account_survey_invitation_submit', methods: ['POST'])]
    public function submit(
        string $token,
        Request $request,
        SurveyInvitationRepository $invitationRepository,
        GtsSurveyRepository $surveyRepository,
        GtsSurveyQuestionRepository $questionRepository,
        GtsSurveyQuestionBank $questionBank,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $context = $this->resolveInvitationContext(
            $token,
            $invitationRepository,
            $surveyRepository,
            $questionRepository,
            $questionBank,
            $entityManager,
            false,
        );

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = json_decode($request->getContent(), true);
        $answers = is_array($payload) && is_array($payload['answers'] ?? null)
            ? $payload['answers']
            : [];

        $user = $this->currentUser();
        $invitation = $invitationRepository->findByToken($token);
        if (!$invitation instanceof SurveyInvitation) {
            return $this->json(['message' => 'Survey invitation was not found.'], 404);
        }

        $template = $invitation->getCampaign()->getSurveyTemplate();
        $runtimeQuestions = $questionBank->createRuntimeQuestions($questionRepository->findActiveOrderedByTemplate($template));

        $survey = (new GtsSurvey())
            ->setUser($user)
            ->setSurveyTemplate($template)
            ->setSurveyInvitation($invitation)
            ->setName(trim($user->getLastName() . ', ' . $user->getFirstName()))
            ->setEmailAddress($user->getEmail())
            ->setInstitutionCode($this->resolveInstitutionCode())
            ->setControlCode($this->generateControlCode($user))
            ->setDynamicAnswers($questionBank->createResponseSnapshot($answers, $runtimeQuestions));

        $alumni = $user->getAlumni();
        if ($alumni !== null) {
            $alumni->setTracerStatus('TRACED');
            $alumni->setLastTracerSubmissionAt(new \DateTime());
        }

        $invitation
            ->setCompletedAt(new \DateTimeImmutable())
            ->setOpenedAt($invitation->getOpenedAt() ?? new \DateTimeImmutable())
            ->setStatus(SurveyInvitation::STATUS_COMPLETED);

        $entityManager->persist($survey);
        $entityManager->persist($invitation);
        $entityManager->flush();

        $this->audit->log('GTS Survey invitation submitted', 'GtsSurvey', $survey->getId());
        $this->notifications->createAdminNotification(
            'gts.response_submitted',
            'New GTS survey response',
            sprintf('%s submitted %s.', $user->getFullName(), $invitation->getCampaign()->getName()),
            Notification::SEVERITY_SUCCESS,
            '/gts/responses',
            'GtsSurvey',
            $survey->getId(),
        );

        return $this->json([
            'message' => 'Your tracer survey was submitted.',
            'item' => [
                ...$context,
                'status' => SurveyInvitation::STATUS_COMPLETED,
                'completedAt' => $this->formatDate($invitation->getCompletedAt()),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function resolveInvitationContext(
        string $token,
        SurveyInvitationRepository $invitationRepository,
        GtsSurveyRepository $surveyRepository,
        GtsSurveyQuestionRepository $questionRepository,
        GtsSurveyQuestionBank $questionBank,
        EntityManagerInterface $entityManager,
        bool $markOpened,
    ): array|JsonResponse {
        $invitation = $invitationRepository->findByToken($token);
        if (!$invitation instanceof SurveyInvitation) {
            return $this->json(['message' => 'Survey invitation was not found.'], 404);
        }

        $user = $this->currentUser();
        if (
            in_array('ROLE_ADMIN', $user->getRoles(), true)
            || in_array('ROLE_STAFF', $user->getRoles(), true)
            || $invitation->getUser()->getId() !== $user->getId()
        ) {
            return $this->json([
                'message' => 'Please sign in with the alumni account that received this survey invitation.',
            ], 403);
        }

        if ($user->getAccountStatus() !== 'active') {
            return $this->json(['message' => 'Only verified alumni accounts can submit the tracer survey.'], 403);
        }

        if ($invitation->isExpired() || $invitation->getStatus() === SurveyInvitation::STATUS_EXPIRED) {
            if ($invitation->getStatus() !== SurveyInvitation::STATUS_EXPIRED) {
                $invitation->setStatus(SurveyInvitation::STATUS_EXPIRED);
                $entityManager->persist($invitation);
                $entityManager->flush();
            }

            return $this->json(['message' => 'This survey invitation has expired.'], 410);
        }

        if (
            $invitation->getStatus() === SurveyInvitation::STATUS_COMPLETED
            || $surveyRepository->hasUserSubmittedForInvitation($user, $invitation)
        ) {
            return $this->json([
                'message' => 'You have already completed this survey invitation.',
                'item' => $this->serializeInvitation($invitation, []),
            ], 409);
        }

        $template = $invitation->getCampaign()->getSurveyTemplate();
        $runtimeQuestions = $questionBank->createRuntimeQuestions($questionRepository->findActiveOrderedByTemplate($template));
        if ($runtimeQuestions === []) {
            return $this->json(['message' => 'This survey template has no active questions yet.'], 409);
        }

        if ($markOpened && $invitation->getOpenedAt() === null) {
            $invitation->setOpenedAt(new \DateTimeImmutable());
            if (in_array($invitation->getStatus(), [SurveyInvitation::STATUS_QUEUED, SurveyInvitation::STATUS_SENT], true)) {
                $invitation->setStatus(SurveyInvitation::STATUS_OPENED);
            }
            $entityManager->persist($invitation);
            $entityManager->flush();
        }

        return $this->serializeInvitation($invitation, $questionBank->groupBySection($runtimeQuestions));
    }

    /**
     * @param list<array{title: string, items: list<array<string, mixed>>}> $questionSections
     *
     * @return array<string, mixed>
     */
    private function serializeInvitation(SurveyInvitation $invitation, array $questionSections): array
    {
        $campaign = $invitation->getCampaign();
        $template = $campaign->getSurveyTemplate();

        return [
            'token' => $invitation->getToken(),
            'status' => $invitation->getStatus(),
            'expiresAt' => $this->formatDate($invitation->getExpiresAt()),
            'openedAt' => $this->formatDate($invitation->getOpenedAt()),
            'completedAt' => $this->formatDate($invitation->getCompletedAt()),
            'campaign' => [
                'id' => $campaign->getId(),
                'name' => $campaign->getName(),
                'emailSubject' => $campaign->getEmailSubject(),
            ],
            'surveyTemplate' => [
                'id' => $template->getId(),
                'title' => $template->getTitle(),
                'description' => $template->getDescription(),
            ],
            'questionSections' => $questionSections,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeInvitationSummary(SurveyInvitation $invitation): array
    {
        $campaign = $invitation->getCampaign();
        $template = $campaign->getSurveyTemplate();

        return [
            'token' => $invitation->getToken(),
            'status' => $invitation->getStatus(),
            'createdAt' => $this->formatDate($invitation->getCreatedAt()),
            'sentAt' => $this->formatDate($invitation->getSentAt()),
            'openedAt' => $this->formatDate($invitation->getOpenedAt()),
            'completedAt' => $this->formatDate($invitation->getCompletedAt()),
            'expiresAt' => $this->formatDate($invitation->getExpiresAt()),
            'campaign' => [
                'id' => $campaign->getId(),
                'name' => $campaign->getName(),
                'emailSubject' => $campaign->getEmailSubject(),
            ],
            'surveyTemplate' => [
                'id' => $template->getId(),
                'title' => $template->getTitle(),
            ],
        ];
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated account is required.');
        }

        return $user;
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

    private function formatDate(?\DateTimeInterface $value): ?string
    {
        return $value?->format(\DateTimeInterface::ATOM);
    }
}
