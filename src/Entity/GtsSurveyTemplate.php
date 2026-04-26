<?php

namespace App\Entity;

use App\Repository\GtsSurveyTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GtsSurveyTemplateRepository::class)]
#[ORM\Table(name: 'gts_survey_template')]
class GtsSurveyTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, GtsSurveyQuestion> */
    #[ORM\OneToMany(mappedBy: 'surveyTemplate', targetEntity: GtsSurveyQuestion::class, cascade: ['remove'])]
    private Collection $questions;

    /** @var Collection<int, SurveyCampaign> */
    #[ORM\OneToMany(mappedBy: 'surveyTemplate', targetEntity: SurveyCampaign::class)]
    private Collection $campaigns;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->questions = new ArrayCollection();
        $this->campaigns = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, GtsSurveyQuestion> */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    /** @return Collection<int, SurveyCampaign> */
    public function getCampaigns(): Collection
    {
        return $this->campaigns;
    }
}
