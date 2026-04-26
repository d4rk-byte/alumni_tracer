<?php

namespace App\Entity;

use App\Repository\GtsSurveyQuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GtsSurveyQuestionRepository::class)]
#[ORM\Table(name: 'gts_survey_question')]
class GtsSurveyQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $questionText;

    #[ORM\Column(length: 50)]
    private string $inputType = 'text';

    #[ORM\Column(length: 120)]
    private string $section = 'General';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $options = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\ManyToOne(inversedBy: 'questions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?GtsSurveyTemplate $surveyTemplate = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestionText(): string
    {
        return $this->questionText;
    }

    public function setQuestionText(string $questionText): static
    {
        $this->questionText = $questionText;

        return $this;
    }

    public function getInputType(): string
    {
        return $this->inputType;
    }

    public function setInputType(string $inputType): static
    {
        $this->inputType = $inputType;

        return $this;
    }

    public function getSection(): string
    {
        return $this->section;
    }

    public function setSection(string $section): static
    {
        $this->section = $section;

        return $this;
    }

    public function getOptions(): ?array
    {
        return $this->options;
    }

    public function setOptions(?array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

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

    public function getSurveyTemplate(): ?GtsSurveyTemplate
    {
        return $this->surveyTemplate;
    }

    public function setSurveyTemplate(?GtsSurveyTemplate $surveyTemplate): static
    {
        $this->surveyTemplate = $surveyTemplate;

        return $this;
    }
}
