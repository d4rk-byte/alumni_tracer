<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\RegistrationDraft;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Bundle\SecurityBundle\Security;

class NotificationService
{
    public function __construct(
        private MailerInterface $mailer,
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private NotificationRepository $notificationRepository,
        private Security $security,
        private string $adminEmail = 'admin@norsu.edu.ph',
    ) {}

    /**
     * @return Notification[]
     */
    public function createAdminNotification(
        string $type,
        string $title,
        string $message,
        string $severity = Notification::SEVERITY_INFO,
        ?string $targetUrl = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?User $actor = null,
        bool $excludeActor = true,
    ): array {
        $actor ??= $this->security->getUser() instanceof User ? $this->security->getUser() : null;
        $notifications = [];

        foreach ($this->userRepository->findActiveAdmins() as $admin) {
            if ($excludeActor && $actor instanceof User && $admin->getId() === $actor->getId()) {
                continue;
            }

            $notification = (new Notification())
                ->setRecipient($admin)
                ->setActor($actor)
                ->setType($type)
                ->setTitle($this->truncate($title, 160))
                ->setMessage($message)
                ->setSeverity($this->normalizeSeverity($severity))
                ->setTargetUrl($targetUrl)
                ->setEntityType($entityType)
                ->setEntityId($entityId);

            $this->entityManager->persist($notification);
            $notifications[] = $notification;
        }

        if ($notifications !== []) {
            $this->entityManager->flush();
        }

        return $notifications;
    }

    /**
     * @return Notification[]
     */
    public function recentFor(User $user, int $limit = 20): array
    {
        return $this->notificationRepository->findRecentForUser($user, $limit);
    }

    /**
     * @return Notification[]
     */
    public function newFor(User $user, int $sinceId, int $limit = 20): array
    {
        return $this->notificationRepository->findNewForUser($user, $sinceId, $limit);
    }

    public function unreadCountFor(User $user): int
    {
        return $this->notificationRepository->countUnreadForUser($user);
    }

    public function markRead(Notification $notification, User $user): bool
    {
        if ($notification->getRecipient()->getId() !== $user->getId()) {
            return false;
        }

        if (!$notification->isRead()) {
            $notification->setReadAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        return true;
    }

    public function markAllRead(User $user): int
    {
        $now = new \DateTimeImmutable();

        return (int) $this->entityManager
            ->createQuery('UPDATE App\\Entity\\Notification n SET n.readAt = :now WHERE n.recipient = :user AND n.readAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('user', $user)
            ->execute();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Notification $notification): array
    {
        $actor = $notification->getActor();

        return [
            'id' => $notification->getId(),
            'type' => $notification->getType(),
            'title' => $notification->getTitle(),
            'message' => $notification->getMessage(),
            'severity' => $notification->getSeverity(),
            'targetUrl' => $notification->getTargetUrl(),
            'entityType' => $notification->getEntityType(),
            'entityId' => $notification->getEntityId(),
            'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'readAt' => $notification->getReadAt()?->format(\DateTimeInterface::ATOM),
            'actor' => $actor instanceof User ? [
                'id' => $actor->getId(),
                'name' => $actor->getFullName(),
                'email' => $actor->getEmail(),
            ] : null,
        ];
    }

    public function notifyNewRegistration(User $user): void
    {
        $this->createAdminNotification(
            'account.registration_pending',
            'New alumni registration',
            sprintf('%s is waiting for account approval.', $user->getFullName()),
            Notification::SEVERITY_INFO,
            '/verification',
            'User',
            $user->getId(),
            actor: null,
            excludeActor: false,
        );

        $email = (new Email())
            ->to($this->adminEmail)
            ->subject('New User Registration — ' . $user->getFullName())
            ->html(sprintf(
                '<h3>New Registration</h3>' .
                '<p><strong>%s</strong> (%s) has registered and is awaiting approval.</p>' .
                '<p>Please log in to the Alumni Tracker to review this registration.</p>',
                htmlspecialchars($user->getFullName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($user->getEmail(), ENT_QUOTES, 'UTF-8')
            ));

        $this->mailer->send($email);
    }

    public function notifyAccountApproved(User $user): void
    {
        $this->createAdminNotification(
            'account.approved',
            'Account approved',
            sprintf('%s was approved.', $user->getFullName()),
            Notification::SEVERITY_SUCCESS,
            '/verification',
            'User',
            $user->getId(),
        );

        $email = (new Email())
            ->to($user->getEmail())
            ->subject('Account Approved — NORSU Alumni Tracker')
            ->html(sprintf(
                '<h3>Welcome, %s!</h3>' .
                '<p>Your account has been approved. You can now log in to the NORSU Alumni Tracker.</p>',
                htmlspecialchars($user->getFirstName(), ENT_QUOTES, 'UTF-8')
            ));

        $this->mailer->send($email);
    }

    public function notifyAccountDenied(User $user): void
    {
        $this->createAdminNotification(
            'account.denied',
            'Account denied',
            sprintf('%s was denied.', $user->getFullName()),
            Notification::SEVERITY_WARNING,
            '/verification',
            'User',
            $user->getId(),
        );

        $email = (new Email())
            ->to($user->getEmail())
            ->subject('Registration Update — NORSU Alumni Tracker')
            ->html(sprintf(
                '<h3>Hello, %s</h3>' .
                '<p>We were unable to approve your registration at this time. ' .
                'Please contact the administrator for more information.</p>',
                htmlspecialchars($user->getFirstName(), ENT_QUOTES, 'UTF-8')
            ));

        $this->mailer->send($email);
    }

    public function sendRegistrationOtp(RegistrationDraft $draft, string $otpCode): void
    {
        $email = (new Email())
            ->to($draft->getEmail())
            ->subject('Your Verification Code — NORSU Alumni Tracker')
            ->html(sprintf(
                '<h3>Hello, %s</h3>' .
                '<p>Use the verification code below to complete your alumni registration.</p>' .
                '<p style="font-size: 32px; font-weight: 700; letter-spacing: 0.3rem; margin: 24px 0;">%s</p>' .
                '<p>This code expires in 10 minutes.</p>' .
                '<p>If you did not start this registration, you can ignore this message.</p>',
                htmlspecialchars($draft->getFirstName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8')
            ));

        $email->getHeaders()->addTextHeader('X-Bus-Transport', 'sync');

        $this->mailer->send($email);
    }

    private function normalizeSeverity(string $severity): string
    {
        return in_array($severity, [
            Notification::SEVERITY_INFO,
            Notification::SEVERITY_SUCCESS,
            Notification::SEVERITY_WARNING,
            Notification::SEVERITY_DANGER,
        ], true) ? $severity : Notification::SEVERITY_INFO;
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 3) . '...' : $value;
    }
}
