<?php

namespace App\Service;

use App\Entity\Alumni;
use App\Entity\SurveyCampaign;
use App\Entity\SurveyInvitation;
use App\Message\SendSurveyInvitationBatchMessage;
use App\Repository\AlumniRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class SurveyCampaignDispatchService
{
    public function __construct(
        private AlumniRepository $alumniRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function dispatchCampaign(SurveyCampaign $campaign, string $baseUrl): int
    {
        $recipientsQb = $this->alumniRepository->searchEligibleSurveyRecipients(
            $campaign->getTargetBatchYear(),
            $campaign->getTargetCollege(),
            $campaign->getTargetCourse(),
        );

        $recipientCount = (int) (clone $recipientsQb)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($recipientCount === 0) {
            return 0;
        }

        $campaign
            ->setStatus('sending')
            ->setSentAt(new \DateTimeImmutable())
            ->setScheduledSendAt(null);

        $this->entityManager->persist($campaign);

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

            $this->entityManager->persist($invitation);
            $invitations[] = $invitation;
        }

        $this->entityManager->flush();

        $invitationIds = array_values(array_filter(array_map(
            static fn (SurveyInvitation $invitation): ?int => $invitation->getId(),
            $invitations,
        )));

        foreach (array_chunk($invitationIds, 100) as $chunk) {
            $this->messageBus->dispatch(new SendSurveyInvitationBatchMessage($campaign->getId(), $chunk, rtrim($baseUrl, '/')));
        }

        return $recipientCount;
    }
}