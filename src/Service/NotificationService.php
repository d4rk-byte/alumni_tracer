<?php

namespace App\Service;

use App\Entity\RegistrationDraft;
use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class NotificationService
{
    public function __construct(
        private MailerInterface $mailer,
        private string $adminEmail = 'admin@norsu.edu.ph',
    ) {}

    public function notifyNewRegistration(User $user): void
    {
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
}
