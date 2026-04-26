<?php

namespace App\Controller;

use App\Entity\Alumni;
use App\Entity\GtsSurveyTemplate;
use App\Entity\SurveyCampaign;
use App\Entity\SurveyInvitation;
use App\Form\Admin\SurveyCampaignLaunchType;
use App\Message\SendSurveyInvitationBatchMessage;
use App\Repository\AlumniRepository;
use App\Repository\SurveyCampaignRepository;
use App\Repository\SurveyInvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        EntityManagerInterface $entityManager,
        MessageBusInterface $messageBus,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_STAFF');

        $yearOptions = array_map(
            static fn ($value): int => (int) $value,
            $alumniRepository->createQueryBuilder('a')
                ->select('DISTINCT a.yearGraduated')
                ->andWhere('a.yearGraduated IS NOT NULL')
                ->andWhere('a.deletedAt IS NULL')
                ->orderBy('a.yearGraduated', 'DESC')
                ->getQuery()
                ->getSingleColumnResult()
        );
        rsort($yearOptions);

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
            'years' => $yearOptions,
            'colleges' => $collegeOptions,
            'courses' => $courseOptions,
        ]);
        $form->handleRequest($request);

        $recipientCount = 0;
        $recipientSample = [];

        if ($form->isSubmitted() && $form->isValid()) {
            $targetYear = $form->get('targetYear')->getData();
            $targetYear = is_numeric((string) $targetYear) ? (int) $targetYear : null;
            $targetCollege = $campaign->getTargetCollege();
            $targetCourse = $campaign->getTargetCourse();

            $recipientsQb = $alumniRepository->searchByBatchCampusCourse($targetYear, $targetCollege, $targetCourse)
                ->leftJoin('a.user', 'u')
                ->andWhere('u.id IS NOT NULL')
                ->andWhere('u.accountStatus = :activeStatus')
                ->andWhere('u.email IS NOT NULL')
                ->andWhere("TRIM(u.email) <> ''")
                ->setParameter('activeStatus', 'active');

            $recipientCount = (int) (clone $recipientsQb)
                ->select('COUNT(a.id)')
                ->getQuery()
                ->getSingleScalarResult();

            $recipientSample = (clone $recipientsQb)
                ->setMaxResults(25)
                ->getQuery()
                ->getResult();

            if ($form->get('send')->isClicked()) {
                if ($targetYear === null) {
                    $this->addFlash('warning', 'Please choose a graduation year.');
                } elseif ($recipientCount === 0) {
                    $this->addFlash('warning', 'No eligible recipients found for the selected filters.');
                } else {
                    $campaign->setTargetGraduationYears([(string) $targetYear]);
                    $campaign->setStatus('sending');
                    $campaign->setSentAt(new \DateTimeImmutable());
                    $campaign->setCreatedBy(method_exists($this->getUser(), 'getEmail') ? $this->getUser()?->getEmail() : null);

                    $entityManager->persist($campaign);

                    $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d days', max(1, $campaign->getExpiryDays())));
                    $invitations = [];

                    foreach ((clone $recipientsQb)->getQuery()->toIterable() as $alumni) {
                        if (!$alumni instanceof Alumni || $alumni->getUser() === null) {
                            continue;
                        }

                        $invitation = (new SurveyInvitation())
                            ->setCampaign($campaign)
                            ->setUser($alumni->getUser())
                            ->setStatus(SurveyInvitation::STATUS_QUEUED)
                            ->setExpiresAt($expiresAt);

                        $entityManager->persist($invitation);
                        $invitations[] = $invitation;
                    }

                    $entityManager->flush();

                    $invitationIds = array_values(array_filter(array_map(
                        static fn (SurveyInvitation $invitation): ?int => $invitation->getId(),
                        $invitations,
                    )));
                    $baseUrl = $request->getSchemeAndHttpHost();

                    foreach (array_chunk($invitationIds, 100) as $chunk) {
                        $messageBus->dispatch(new SendSurveyInvitationBatchMessage($campaign->getId(), $chunk, $baseUrl));
                    }

                    $this->addFlash('success', sprintf('Campaign queued with %d invitation email(s). Sending will continue in the background.', $recipientCount));

                    return $this->redirectToRoute(
                        $this->isGranted('ROLE_ADMIN') ? 'admin_gts_surveys_index' : 'staff_gts_surveys_index'
                    );
                }
            }
        }

        return $this->render('admin/gts_campaigns/new.html.twig', [
            'surveyTemplate' => $surveyTemplate,
            'form' => $form->createView(),
            'recipientCount' => $recipientCount,
            'recipientSample' => $recipientSample,
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
