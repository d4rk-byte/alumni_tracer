<?php

namespace App\Controller;

use App\Entity\Alumni;
use App\Entity\GtsSurveyTemplate;
use App\Entity\SurveyCampaign;
use App\Entity\SurveyInvitation;
use App\Form\Admin\SurveyCampaignLaunchType;
use App\Message\SendSurveyInvitationBatchMessage;
use App\Repository\AlumniRepository;
use App\Repository\QrRegistrationBatchRepository;
use App\Repository\SurveyCampaignRepository;
use App\Repository\SurveyInvitationRepository;
use App\Service\SurveyCampaignDispatchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class AdminSurveyCampaignController extends AbstractController
{
    #[Route('/admin/gts/surveys/{id}/campaigns', name: 'admin_gts_campaign_index', methods: ['GET'])]
    #[Route('/staff/gts/surveys/{id}/campaigns', name: 'staff_gts_campaign_index', methods: ['GET'])]
    public function index(GtsSurveyTemplate $surveyTemplate, SurveyCampaignRepository $campaignRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $campaigns = $campaignRepository->findBy(['surveyTemplate' => $surveyTemplate], ['createdAt' => 'DESC']);

        return $this->render('admin/gts_campaigns/index.html.twig', [
            'surveyTemplate' => $surveyTemplate,
            'campaigns' => $campaigns,
        ]);
    }

    #[Route('/admin/gts/surveys/{id}/campaigns/new', name: 'admin_gts_campaign_new', methods: ['GET', 'POST'])]
    #[Route('/staff/gts/surveys/{id}/campaigns/new', name: 'staff_gts_campaign_new', methods: ['GET', 'POST'])]
    public function new(
        GtsSurveyTemplate $surveyTemplate,
        Request $request,
        AlumniRepository $alumniRepository,
        QrRegistrationBatchRepository $batchRepository,
        EntityManagerInterface $entityManager,
        SurveyCampaignDispatchService $campaignDispatchService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $batchYearOptions = array_values(array_unique(array_map(
            static fn ($batch): int => $batch->getBatchYear(),
            $batchRepository->findAllOrdered()
        )));
        rsort($batchYearOptions);

        $alumniYearOptions = array_map(
            static fn ($value): int => (int) $value,
            $alumniRepository->createQueryBuilder('a')
                ->select('DISTINCT a.yearGraduated')
                ->andWhere('a.yearGraduated IS NOT NULL')
                ->andWhere('a.deletedAt IS NULL')
                ->orderBy('a.yearGraduated', 'DESC')
                ->getQuery()
                ->getSingleColumnResult()
        );
        rsort($alumniYearOptions);

        if ($batchYearOptions === []) {
            $batchYearOptions = $alumniYearOptions;
        }

        $collegeOptions = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $alumniRepository->createQueryBuilder('a')
                ->select('DISTINCT a.college')
                ->andWhere('a.college IS NOT NULL')
                ->andWhere("TRIM(a.college) <> ''")
                ->andWhere('a.deletedAt IS NULL')
                ->orderBy('a.college', 'ASC')
                ->getQuery()
                ->getSingleColumnResult()
        )));

        $courseOptions = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $alumniRepository->createQueryBuilder('a')
                ->select('DISTINCT a.course')
                ->andWhere('a.course IS NOT NULL')
                ->andWhere("TRIM(a.course) <> ''")
                ->andWhere('a.deletedAt IS NULL')
                ->orderBy('a.course', 'ASC')
                ->getQuery()
                ->getSingleColumnResult()
        )));

        $campaign = (new SurveyCampaign())
            ->setSurveyTemplate($surveyTemplate)
            ->setName(sprintf('%s Alumni Campaign', $surveyTemplate->getTitle()))
            ->setEmailSubject(sprintf('Graduate Tracer Survey Invitation - %s', $surveyTemplate->getTitle()))
            ->setEmailBody("Good day!\n\nYou are invited to complete the Graduate Tracer Survey. Please log in using your alumni account and submit your response before the invitation expires.\n\nThank you.")
            ->setExpiryDays(30);

        $form = $this->createForm(SurveyCampaignLaunchType::class, $campaign, [
            'batch_years' => $batchYearOptions,
            'colleges' => $collegeOptions,
            'courses' => $courseOptions,
        ]);
        $form->handleRequest($request);

        $recipientCount = 0;
        $recipientSample = [];
        $hasBatchOptions = $batchYearOptions !== [];

        if ($form->isSubmitted() && $form->isValid()) {
            $targetBatchYear = $form->get('targetBatchYear')->getData();
            $targetBatchYear = is_numeric((string) $targetBatchYear) ? (int) $targetBatchYear : null;
            $targetCollege = $campaign->getTargetCollege();
            $targetCourse = $campaign->getTargetCourse();

            $recipientsQb = $alumniRepository->searchEligibleSurveyRecipients($targetBatchYear, $targetCollege, $targetCourse);

            $recipientCount = (int) (clone $recipientsQb)
                ->select('COUNT(a.id)')
                ->getQuery()
                ->getSingleScalarResult();

            $recipientSample = (clone $recipientsQb)
                ->setMaxResults(25)
                ->getQuery()
                ->getResult();

            if ($form->get('send')->isClicked()) {
                if ($targetBatchYear === null) {
                    $this->addFlash('warning', 'Please choose a target batch year.');
                } elseif ($recipientCount === 0) {
                    $this->addFlash('warning', 'No eligible recipients found for the selected filters.');
                } else {
                    $campaign
                        ->setTargetBatchYear($targetBatchYear)
                        ->setCreatedBy(method_exists($this->getUser(), 'getEmail') ? $this->getUser()?->getEmail() : null)
                        ->setScheduledSendAt(null);

                    $campaignDispatchService->dispatchCampaign($campaign, $request->getSchemeAndHttpHost());

                    $this->addFlash('success', sprintf('Campaign queued with %d invitation email(s). Sending will continue in the background.', $recipientCount));

                    return $this->redirectToRoute(
                        $this->isGranted('ROLE_ADMIN') ? 'admin_gts_surveys_index' : 'staff_gts_surveys_index'
                    );
                }
            } elseif ($form->get('schedule')->isClicked()) {
                $scheduledSendAt = $campaign->getScheduledSendAt();

                if ($targetBatchYear === null) {
                    $this->addFlash('warning', 'Please choose a target batch year.');
                } elseif (!$scheduledSendAt instanceof \DateTimeImmutable) {
                    $this->addFlash('warning', 'Please choose when the campaign should be sent.');
                } elseif ($scheduledSendAt <= new \DateTimeImmutable()) {
                    $this->addFlash('warning', 'Please choose a future send date, or use Send Now to dispatch the campaign immediately.');
                } else {
                    $campaign
                        ->setTargetBatchYear($targetBatchYear)
                        ->setStatus('scheduled')
                        ->setSentAt(null)
                        ->setCreatedBy(method_exists($this->getUser(), 'getEmail') ? $this->getUser()?->getEmail() : null);

                    $entityManager->persist($campaign);
                    $entityManager->flush();

                    $this->addFlash('success', sprintf(
                        'Campaign scheduled for %s. Eligible recipients will be checked again when it is time to send.',
                        $scheduledSendAt->format('M d, Y h:i A')
                    ));

                    return $this->redirectToRoute(
                        $this->isGranted('ROLE_ADMIN') ? 'admin_gts_campaign_show' : 'staff_gts_campaign_show',
                        ['id' => $campaign->getId()]
                    );
                }
            }
        }

        return $this->render('admin/gts_campaigns/new.html.twig', [
            'surveyTemplate' => $surveyTemplate,
            'form' => $form->createView(),
            'recipientCount' => $recipientCount,
            'recipientSample' => $recipientSample,
            'hasBatchOptions' => $hasBatchOptions,
            'previewEndpoint' => $this->generateUrl(
                $this->isGranted('ROLE_ADMIN') ? 'admin_gts_campaign_preview' : 'staff_gts_campaign_preview',
                ['id' => $surveyTemplate->getId()]
            ),
        ]);
    }

    #[Route('/admin/gts/surveys/{id}/campaigns/preview', name: 'admin_gts_campaign_preview', methods: ['GET'])]
    #[Route('/staff/gts/surveys/{id}/campaigns/preview', name: 'staff_gts_campaign_preview', methods: ['GET'])]
    public function previewRecipients(
        GtsSurveyTemplate $surveyTemplate,
        Request $request,
        AlumniRepository $alumniRepository,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $targetBatchYearRaw = $request->query->get('targetBatchYear');
        $targetBatchYear = is_numeric((string) $targetBatchYearRaw) ? (int) $targetBatchYearRaw : null;
        $targetCollege = trim((string) $request->query->get('targetCollege', ''));
        $targetCourse = trim((string) $request->query->get('targetCourse', ''));

        if ($targetBatchYear === null) {
            return $this->json([
                'count' => 0,
                'rows' => [],
                'surveyTemplateId' => $surveyTemplate->getId(),
            ]);
        }

        $recipientsQb = $alumniRepository->searchEligibleSurveyRecipients(
            $targetBatchYear,
            $targetCollege !== '' ? $targetCollege : null,
            $targetCourse !== '' ? $targetCourse : null,
        );

        $recipientCount = (int) (clone $recipientsQb)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $recipients = (clone $recipientsQb)
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        $rows = array_map(static function (Alumni $alumni): array {
            $currentEmail = trim((string) ($alumni->getUser()?->getEmail() ?? ''));

            return [
                'id' => $alumni->getId(),
                'name' => sprintf('%s, %s', $alumni->getLastName(), $alumni->getFirstName()),
                'studentNumber' => $alumni->getStudentNumber(),
                'email' => $currentEmail !== '' ? $currentEmail : $alumni->getEmailAddress(),
                'course' => $alumni->getCourse() ?: 'No course',
                'college' => $alumni->getCollege() ?: 'No college',
            ];
        }, $recipients);

        return $this->json([
            'count' => $recipientCount,
            'rows' => $rows,
            'surveyTemplateId' => $surveyTemplate->getId(),
        ]);
    }

    #[Route('/admin/gts/campaigns/{id}', name: 'admin_gts_campaign_show', methods: ['GET'])]
    #[Route('/staff/gts/campaigns/{id}', name: 'staff_gts_campaign_show', methods: ['GET'])]
    public function show(SurveyCampaign $campaign, SurveyInvitationRepository $invitationRepository, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $statusFilter = $request->query->get('status');
        if ($statusFilter === '') {
            $statusFilter = null;
        }

        $invitations = $invitationRepository->findByCampaign($campaign, $statusFilter);
        
        $counts = [
            'total' => $invitationRepository->countByCampaign($campaign),
            'queued' => $invitationRepository->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_QUEUED),
            'sent' => $invitationRepository->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_SENT),
            'opened' => $invitationRepository->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_OPENED),
            'completed' => $invitationRepository->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_COMPLETED),
            'expired' => $invitationRepository->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_EXPIRED),
            'failed' => $invitationRepository->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_FAILED),
        ];

        return $this->render('admin/gts_campaigns/show.html.twig', [
            'campaign' => $campaign,
            'invitations' => $invitations,
            'counts' => $counts,
            'currentStatus' => $statusFilter,
        ]);
    }

    #[Route('/admin/gts/invitations/{id}/resend', name: 'admin_gts_invitation_resend', methods: ['POST'])]
    #[Route('/staff/gts/invitations/{id}/resend', name: 'staff_gts_invitation_resend', methods: ['POST'])]
    public function resend(
        SurveyInvitation $invitation,
        Request $request,
        EntityManagerInterface $entityManager,
        MessageBusInterface $messageBus
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        if ($this->isCsrfTokenValid('resend_invitation_' . $invitation->getId(), (string) $request->request->get('_token'))) {
            $invitation->setStatus(SurveyInvitation::STATUS_QUEUED);
            $invitation->setFailedAt(null);
            $invitation->setFailureReason(null);
            $invitation->setSentAt(null);
            $invitation->setExpiresAt((new \DateTimeImmutable())->modify(sprintf('+%d days', max(1, $invitation->getCampaign()->getExpiryDays()))));

            $entityManager->flush();

            $baseUrl = $request->getSchemeAndHttpHost();
            $messageBus->dispatch(new SendSurveyInvitationBatchMessage($invitation->getCampaign()->getId(), [$invitation->getId()], $baseUrl));

            $this->addFlash('success', 'Invitation queued for resending.');
        }

        $redirectRoute = $this->isGranted('ROLE_ADMIN') ? 'admin_gts_campaign_show' : 'staff_gts_campaign_show';
        return $this->redirectToRoute($redirectRoute, ['id' => $invitation->getCampaign()->getId()]);
    }
}
