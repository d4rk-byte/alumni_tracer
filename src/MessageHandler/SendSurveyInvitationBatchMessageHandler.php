<?php

namespace App\MessageHandler;

use App\Entity\SurveyInvitation;
use App\Message\SendSurveyInvitationBatchMessage;
use App\Repository\SurveyCampaignRepository;
use App\Repository\SurveyInvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendSurveyInvitationBatchMessageHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private SurveyCampaignRepository $campaignRepository,
        private SurveyInvitationRepository $invitationRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SendSurveyInvitationBatchMessage $message): void
    {
        $campaign = $this->campaignRepository->find($message->getCampaignId());
        if ($campaign === null) {
            return;
        }

        $baseUrl = rtrim($message->getBaseUrl(), '/');

        foreach ($message->getInvitationIds() as $invitationId) {
            $invitation = $this->invitationRepository->find($invitationId);
            if (!$invitation instanceof SurveyInvitation) {
                continue;
            }

            if ($invitation->getStatus() !== SurveyInvitation::STATUS_QUEUED) {
                continue;
            }

            $emailAddress = trim((string) $invitation->getUser()->getEmail());
            if ($emailAddress === '') {
                $invitation
                    ->setStatus(SurveyInvitation::STATUS_FAILED)
                    ->setFailedAt(new \DateTimeImmutable())
                    ->setFailureReason('Recipient email is missing.');
                continue;
            }

            try {
                $email = (new TemplatedEmail())
                    ->to($emailAddress)
                    ->subject($campaign->getEmailSubject())
                    ->htmlTemplate('emails/survey_invitation.html.twig')
                    ->context([
                        'campaign' => $campaign,
                        'invitation' => $invitation,
                        'invitationUrl' => $baseUrl . '/gts/invitations/' . $invitation->getToken(),
                    ]);

                $this->mailer->send($email);

                $invitation
                    ->setStatus(SurveyInvitation::STATUS_SENT)
                    ->setSentAt(new \DateTimeImmutable())
                    ->setFailedAt(null)
                    ->setFailureReason(null);
            } catch (\Throwable $exception) {
                $invitation
                    ->setStatus(SurveyInvitation::STATUS_FAILED)
                    ->setFailedAt(new \DateTimeImmutable())
                    ->setFailureReason(substr($exception->getMessage(), 0, 1000));
            }
        }

        // Flush invitation changes before potentially updating campaign state.
        $this->entityManager->flush();

        if ($this->invitationRepository->countByCampaignAndStatus($campaign, SurveyInvitation::STATUS_QUEUED) === 0) {
            $campaign->setStatus('sent');
            if ($campaign->getSentAt() === null) {
                $campaign->setSentAt(new \DateTimeImmutable());
            }
            $this->entityManager->flush();
        }
    }
}
