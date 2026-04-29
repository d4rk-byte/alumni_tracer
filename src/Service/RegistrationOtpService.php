<?php

namespace App\Service;

use App\Entity\RegistrationDraft;

class RegistrationOtpService
{
    public const OTP_LENGTH = 6;
    public const OTP_LIFETIME_MINUTES = 10;
    public const MAX_VERIFY_ATTEMPTS = 5;
    public const MAX_RESEND_COUNT = 3;

    public function issueOtp(RegistrationDraft $draft, bool $isResend = false): string
    {
        $otpCode = str_pad((string) random_int(0, (10 ** self::OTP_LENGTH) - 1), self::OTP_LENGTH, '0', STR_PAD_LEFT);

        $draft
            ->setOtpCodeHash(password_hash($otpCode, PASSWORD_DEFAULT))
            ->setOtpExpiresAt(new \DateTimeImmutable(sprintf('+%d minutes', self::OTP_LIFETIME_MINUTES)))
            ->setVerifyAttempts(0)
            ->setVerifiedAt(null);

        if ($isResend) {
            $draft->incrementResendCount();
        }

        return $otpCode;
    }

    public function isExpired(RegistrationDraft $draft): bool
    {
        return $draft->getOtpExpiresAt() <= new \DateTimeImmutable();
    }

    public function hasVerifyAttemptsRemaining(RegistrationDraft $draft): bool
    {
        return $draft->getVerifyAttempts() < self::MAX_VERIFY_ATTEMPTS;
    }

    public function hasResendsRemaining(RegistrationDraft $draft): bool
    {
        return $draft->getResendCount() < self::MAX_RESEND_COUNT;
    }

    public function isCodeValid(RegistrationDraft $draft, string $submittedCode): bool
    {
        $normalizedCode = trim($submittedCode);

        if ($normalizedCode === '') {
            return false;
        }

        return password_verify($normalizedCode, $draft->getOtpCodeHash());
    }

    public function getRemainingAttempts(RegistrationDraft $draft): int
    {
        return max(0, self::MAX_VERIFY_ATTEMPTS - $draft->getVerifyAttempts());
    }

    public function getRemainingResends(RegistrationDraft $draft): int
    {
        return max(0, self::MAX_RESEND_COUNT - $draft->getResendCount());
    }
}