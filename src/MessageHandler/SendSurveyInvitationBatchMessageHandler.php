<?php

namespace App\MessageHandler;

use App\Entity\Notification;
use App\Entity\SurveyInvitation;
use App\Message\SendSurveyInvitationBatchMessage;
use App\Repository\SurveyCampaignRepository;
use App\Repository\SurveyInvitationRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
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
        private NotificationService $notificationService,
    ) {
    }

    public function __invoke(SendSurveyInvitationBatchMessage $message): void
    {
        $campaign = $this->campaignRepository->find($message->getCampaignId());
        if ($campaign === null) {
            return;
        }

        $baseUrl = rtrim($this->resolveFrontendBaseUrl($message->getBaseUrl()), '/');
        $targetBatchYear = $campaign->getTargetBatchYear();
        $failedCount = 0;
        $sentCount = 0;

        foreach ($message->getInvitationIds() as $invitationId) {
            $invitation = $this->invitationRepository->find($invitationId);
            if (!$invitation instanceof SurveyInvitation) {
                ++$failedCount;
                continue;
            }

            if ($invitation->getStatus() !== SurveyInvitation::STATUS_QUEUED) {
                continue;
            }

            $recipient = $invitation->getUser();
            $alumni = $recipient->getAlumni();
            if ($targetBatchYear === null || $alumni === null || $alumni->getYearGraduated() !== $targetBatchYear) {
                $invitation
                    ->setStatus(SurveyInvitation::STATUS_FAILED)
                    ->setFailedAt(new \DateTimeImmutable())
                    ->setFailureReason('Recipient batch does not match the campaign target batch.');
                ++$failedCount;
                continue;
            }

            $emailAddress = trim((string) ($alumni?->getEmailAddress() ?: $recipient->getEmail()));
            if ($emailAddress === '') {
                $invitation
                    ->setStatus(SurveyInvitation::STATUS_FAILED)
                    ->setFailedAt(new \DateTimeImmutable())
                    ->setFailureReason('Recipient email is missing.');
                ++$failedCount;
                continue;
            }

            try {
                $email = (new TemplatedEmail())
                    ->to(new Address($emailAddress, $alumni?->getFullName() ?: $recipient->getFullName()))
                    ->subject($campaign->getEmailSubject())
                    ->htmlTemplate('emails/survey_invitation.html.twig')
                    ->textTemplate('emails/survey_invitation.txt.twig')
                    ->context([
                        'campaign' => $campaign,
                        'invitation' => $invitation,
                        'invitationUrl' => $baseUrl . '/survey/invitations/' . $invitation->getToken(),
                    ]);

                $this->mailer->send($email);

                $invitation
                    ->setStatus(SurveyInvitation::STATUS_SENT)
                    ->setSentAt(new \DateTimeImmutable())
                    ->setFailedAt(null)
                    ->setFailureReason(null);
                ++$sentCount;
            } catch (\Throwable $exception) {
                $invitation
                    ->setStatus(SurveyInvitation::STATUS_FAILED)
                    ->setFailedAt(new \DateTimeImmutable())
                    ->setFailureReason(substr($exception->getMessage(), 0, 1000));
                ++$failedCount;
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

            $this->notificationService->createAdminNotification(
                'gts.campaign_sent',
                'GTS campaign sent',
                sprintf('%s finished sending to %d alumni.', $campaign->getName(), $sentCount),
                Notification::SEVERITY_SUCCESS,
                '/gts/campaigns',
                'SurveyCampaign',
                $campaign->getId(),
                actor: null,
                excludeActor: false,
            );
        }

        if ($failedCount > 0) {
            $this->notificationService->createAdminNotification(
                'gts.campaign_send_failed',
                'GTS campaign send failure',
                sprintf('%s had %d invitation failure(s).', $campaign->getName(), $failedCount),
                Notification::SEVERITY_DANGER,
                '/gts/campaigns',
                'SurveyCampaign',
                $campaign->getId(),
                actor: null,
                excludeActor: false,
            );
        }
    }

    private function resolveFrontendBaseUrl(string $fallbackBaseUrl): string
    {
        $configuredUrl = trim((string) ($_ENV['FRONTEND_URL'] ?? $_SERVER['FRONTEND_URL'] ?? ''));

        return $configuredUrl !== '' ? $configuredUrl : $fallbackBaseUrl;
    }
}
