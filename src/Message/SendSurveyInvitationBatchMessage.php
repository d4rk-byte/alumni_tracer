<?php

namespace App\Message;

final class SendSurveyInvitationBatchMessage
{
    /**
     * @param list<int> $invitationIds
     */
    public function __construct(
        private int $campaignId,
        private array $invitationIds,
        private string $baseUrl,
    ) {
    }

    public function getCampaignId(): int
    {
        return $this->campaignId;
    }

    /** @return list<int> */
    public function getInvitationIds(): array
    {
        return $this->invitationIds;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
