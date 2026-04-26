<?php

namespace App\Entity;

use App\Repository\GtsSurveyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: GtsSurveyRepository::class)]
#[ORM\Table(name: 'gts_survey')]
#[ORM\UniqueConstraint(name: 'uniq_gts_survey_user_template', columns: ['user_id', 'survey_template_id'])]
#[ORM\UniqueConstraint(name: 'uniq_gts_survey_invitation', columns: ['survey_invitation_id'])]
#[Assert\Callback('validateSurveyOwnerRole')]
#[ORM\HasLifecycleCallbacks]
class GtsSurvey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'gtsSurveys')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: GtsSurveyTemplate::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?GtsSurveyTemplate $surveyTemplate = null;

    #[ORM\OneToOne(targetEntity: SurveyInvitation::class)]
    #[ORM\JoinColumn(nullable: true, unique: true)]
    private ?SurveyInvitation $surveyInvitation = null;

    // ═══════════════════════════════════════════════════════
    //  A. GENERAL INFORMATION (Q1–Q11)
    // ═══════════════════════════════════════════════════════

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $institutionCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $controlCode = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $permanentAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailAddress = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $telephoneNumber = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mobileNumber = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $civilStatus = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $sex = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $birthday = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $regionOfOrigin = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $province = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $locationOfResidence = null;

    /** @var array<string, mixed>|null Responses to admin-configured dynamic survey questions */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dynamicAnswers = null;

    // ═══════════════════════════════════════════════════════
    //  B. EDUCATIONAL BACKGROUND (Q12–Q14)
    // ═══════════════════════════════════════════════════════

    /** @var array Q12 – array of {degree, specialization, college, yearGraduated, honors} */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $educationalAttainment = null;

    /** @var array Q13 – array of {name, dateTaken, rating} */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $professionalExams = null;

    /** @var array Q14 – Reasons for taking the course (undergraduate) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $reasonsForCourseUndergrad = null;

    /** @var array Q14 – Reasons for taking the course (graduate) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $reasonsForCourseGrad = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reasonsForCourseOther = null;

    // ═══════════════════════════════════════════════════════
    //  C. TRAINING / ADVANCE STUDIES (Q15)
    // ═══════════════════════════════════════════════════════

    /** @var array Q15a – array of {title, duration, institution} */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $trainings = null;

    /** @var array Q15b – Reasons for advance studies */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $reasonsAdvanceStudy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reasonAdvanceStudyOther = null;

    // ═══════════════════════════════════════════════════════
    //  D. EMPLOYMENT DATA (Q16–Q34)
    // ═══════════════════════════════════════════════════════

    /** Q16 – Yes / No / Never Employed */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $presentlyEmployed = null;

    /** @var array Q17 – Reasons not employed (multiple) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $reasonsNotEmployed = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reasonNotEmployedOther = null;

    /** Q18 – Present Employment Status */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $presentEmploymentStatus = null;

    /** Q19 – Present Occupation (PSOC) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $presentOccupation = null;

    /** Q20a – Company name & address */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $companyNameAddress = null;

    /** Q20b – Major line of business */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lineOfBusiness = null;

    /** Q21 – Local / Abroad */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $placeOfWork = null;

    /** Q22 – First job after college? */
    #[ORM\Column(nullable: true)]
    private ?bool $isFirstJobAfterCollege = null;

    /** @var array Q23 – Reasons for staying on the job */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $reasonsForStaying = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reasonForStayingOther = null;

    /** Q24 – First job related to course? */
    #[ORM\Column(nullable: true)]
    private ?bool $firstJobRelatedToCourse = null;

    /** @var array Q25 – Reasons for accepting the job */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $reasonsForAccepting = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reasonForAcceptingOther = null;

    /** @var array Q26 – Reasons for changing job */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $reasonsForChanging = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reasonForChangingOther = null;

    /** Q27 – How long in first job */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $durationFirstJob = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $durationFirstJobOther = null;

    /** @var array Q28 – How did you find your first job */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $howFoundFirstJob = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $howFoundFirstJobOther = null;

    /** Q29 – How long to land first job */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $timeToLandFirstJob = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $timeToLandFirstJobOther = null;

    /** Q30.1 – Job level first job */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $jobLevelFirstJob = null;

    /** Q30.2 – Job level current job */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $jobLevelCurrentJob = null;

    /** Q31 – Initial gross monthly earning */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $initialMonthlyEarning = null;

    /** Q32 – Curriculum relevant to first job? */
    #[ORM\Column(nullable: true)]
    private ?bool $curriculumRelevant = null;

    /** @var array Q33 – Competencies useful (multiple) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $competenciesUseful = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $competenciesUsefulOther = null;

    /** Q34 – Suggestions to improve curriculum */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $suggestions = null;

    // ═══════════════════════════════════════════════════════
    //  Timestamps
    // ═══════════════════════════════════════════════════════

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime();
    }

    // ═══════════════════════════════════════════════════════
    //  GETTERS & SETTERS
    // ═══════════════════════════════════════════════════════

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getSurveyTemplate(): ?GtsSurveyTemplate { return $this->surveyTemplate; }
    public function setSurveyTemplate(?GtsSurveyTemplate $surveyTemplate): static
    {
        $this->surveyTemplate = $surveyTemplate;
        return $this;
    }

    public function getSurveyInvitation(): ?SurveyInvitation { return $this->surveyInvitation; }
    public function setSurveyInvitation(?SurveyInvitation $surveyInvitation): static
    {
        $this->surveyInvitation = $surveyInvitation;
        return $this;
    }

    // -- A. General Information --

    public function getInstitutionCode(): ?string { return $this->institutionCode; }
    public function setInstitutionCode(?string $v): static { $this->institutionCode = $v; return $this; }

    public function getControlCode(): ?string { return $this->controlCode; }
    public function setControlCode(?string $v): static { $this->controlCode = $v; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getPermanentAddress(): ?string { return $this->permanentAddress; }
    public function setPermanentAddress(?string $v): static { $this->permanentAddress = $v; return $this; }

    public function getEmailAddress(): ?string { return $this->emailAddress; }
    public function setEmailAddress(?string $v): static { $this->emailAddress = $v; return $this; }

    public function getTelephoneNumber(): ?string { return $this->telephoneNumber; }
    public function setTelephoneNumber(?string $v): static { $this->telephoneNumber = $v; return $this; }

    public function getMobileNumber(): ?string { return $this->mobileNumber; }
    public function setMobileNumber(?string $v): static { $this->mobileNumber = $v; return $this; }

    public function getCivilStatus(): ?string { return $this->civilStatus; }
    public function setCivilStatus(?string $v): static { $this->civilStatus = $v; return $this; }

    public function getSex(): ?string { return $this->sex; }
    public function setSex(?string $v): static { $this->sex = $v; return $this; }

    public function getBirthday(): ?\DateTimeInterface { return $this->birthday; }
    public function setBirthday(?\DateTimeInterface $v): static { $this->birthday = $v; return $this; }

    public function getRegionOfOrigin(): ?string { return $this->regionOfOrigin; }
    public function setRegionOfOrigin(?string $v): static { $this->regionOfOrigin = $v; return $this; }

    public function getProvince(): ?string { return $this->province; }
    public function setProvince(?string $v): static { $this->province = $v; return $this; }

    public function getLocationOfResidence(): ?string { return $this->locationOfResidence; }
    public function setLocationOfResidence(?string $v): static { $this->locationOfResidence = $v; return $this; }

    public function getDynamicAnswers(): ?array { return $this->dynamicAnswers; }
    public function setDynamicAnswers(?array $v): static { $this->dynamicAnswers = $v; return $this; }

    // -- B. Educational Background --

    public function getEducationalAttainment(): ?array { return $this->educationalAttainment; }
    public function setEducationalAttainment(?array $v): static { $this->educationalAttainment = $v; return $this; }

    public function getProfessionalExams(): ?array { return $this->professionalExams; }
    public function setProfessionalExams(?array $v): static { $this->professionalExams = $v; return $this; }

    public function getReasonsForCourseUndergrad(): ?array { return $this->reasonsForCourseUndergrad; }
    public function setReasonsForCourseUndergrad(?array $v): static { $this->reasonsForCourseUndergrad = $v; return $this; }

    public function getReasonsForCourseGrad(): ?array { return $this->reasonsForCourseGrad; }
    public function setReasonsForCourseGrad(?array $v): static { $this->reasonsForCourseGrad = $v; return $this; }

    public function getReasonsForCourseOther(): ?string { return $this->reasonsForCourseOther; }
    public function setReasonsForCourseOther(?string $v): static { $this->reasonsForCourseOther = $v; return $this; }

    // -- C. Training --

    public function getTrainings(): ?array { return $this->trainings; }
    public function setTrainings(?array $v): static { $this->trainings = $v; return $this; }

    public function getReasonsAdvanceStudy(): ?array { return $this->reasonsAdvanceStudy; }
    public function setReasonsAdvanceStudy(?array $v): static { $this->reasonsAdvanceStudy = $v; return $this; }

    public function getReasonAdvanceStudyOther(): ?string { return $this->reasonAdvanceStudyOther; }
    public function setReasonAdvanceStudyOther(?string $v): static { $this->reasonAdvanceStudyOther = $v; return $this; }

    // -- D. Employment Data --

    public function getPresentlyEmployed(): ?string { return $this->presentlyEmployed; }
    public function setPresentlyEmployed(?string $v): static { $this->presentlyEmployed = $v; return $this; }

    public function getReasonsNotEmployed(): ?array { return $this->reasonsNotEmployed; }
    public function setReasonsNotEmployed(?array $v): static { $this->reasonsNotEmployed = $v; return $this; }

    public function getReasonNotEmployedOther(): ?string { return $this->reasonNotEmployedOther; }
    public function setReasonNotEmployedOther(?string $v): static { $this->reasonNotEmployedOther = $v; return $this; }

    public function getPresentEmploymentStatus(): ?string { return $this->presentEmploymentStatus; }
    public function setPresentEmploymentStatus(?string $v): static { $this->presentEmploymentStatus = $v; return $this; }

    public function getPresentOccupation(): ?string { return $this->presentOccupation; }
    public function setPresentOccupation(?string $v): static { $this->presentOccupation = $v; return $this; }

    public function getCompanyNameAddress(): ?string { return $this->companyNameAddress; }
    public function setCompanyNameAddress(?string $v): static { $this->companyNameAddress = $v; return $this; }

    public function getLineOfBusiness(): ?string { return $this->lineOfBusiness; }
    public function setLineOfBusiness(?string $v): static { $this->lineOfBusiness = $v; return $this; }

    public function getPlaceOfWork(): ?string { return $this->placeOfWork; }
    public function setPlaceOfWork(?string $v): static { $this->placeOfWork = $v; return $this; }

    public function isFirstJobAfterCollege(): ?bool { return $this->isFirstJobAfterCollege; }
    public function setIsFirstJobAfterCollege(?bool $v): static { $this->isFirstJobAfterCollege = $v; return $this; }

    public function getReasonsForStaying(): ?array { return $this->reasonsForStaying; }
    public function setReasonsForStaying(?array $v): static { $this->reasonsForStaying = $v; return $this; }

    public function getReasonForStayingOther(): ?string { return $this->reasonForStayingOther; }
    public function setReasonForStayingOther(?string $v): static { $this->reasonForStayingOther = $v; return $this; }

    public function isFirstJobRelatedToCourse(): ?bool { return $this->firstJobRelatedToCourse; }
    public function setFirstJobRelatedToCourse(?bool $v): static { $this->firstJobRelatedToCourse = $v; return $this; }

    public function getReasonsForAccepting(): ?array { return $this->reasonsForAccepting; }
    public function setReasonsForAccepting(?array $v): static { $this->reasonsForAccepting = $v; return $this; }

    public function getReasonForAcceptingOther(): ?string { return $this->reasonForAcceptingOther; }
    public function setReasonForAcceptingOther(?string $v): static { $this->reasonForAcceptingOther = $v; return $this; }

    public function getReasonsForChanging(): ?array { return $this->reasonsForChanging; }
    public function setReasonsForChanging(?array $v): static { $this->reasonsForChanging = $v; return $this; }

    public function getReasonForChangingOther(): ?string { return $this->reasonForChangingOther; }
    public function setReasonForChangingOther(?string $v): static { $this->reasonForChangingOther = $v; return $this; }

    public function getDurationFirstJob(): ?string { return $this->durationFirstJob; }
    public function setDurationFirstJob(?string $v): static { $this->durationFirstJob = $v; return $this; }

    public function getDurationFirstJobOther(): ?string { return $this->durationFirstJobOther; }
    public function setDurationFirstJobOther(?string $v): static { $this->durationFirstJobOther = $v; return $this; }

    public function getHowFoundFirstJob(): ?array { return $this->howFoundFirstJob; }
    public function setHowFoundFirstJob(?array $v): static { $this->howFoundFirstJob = $v; return $this; }

    public function getHowFoundFirstJobOther(): ?string { return $this->howFoundFirstJobOther; }
    public function setHowFoundFirstJobOther(?string $v): static { $this->howFoundFirstJobOther = $v; return $this; }

    public function getTimeToLandFirstJob(): ?string { return $this->timeToLandFirstJob; }
    public function setTimeToLandFirstJob(?string $v): static { $this->timeToLandFirstJob = $v; return $this; }

    public function getTimeToLandFirstJobOther(): ?string { return $this->timeToLandFirstJobOther; }
    public function setTimeToLandFirstJobOther(?string $v): static { $this->timeToLandFirstJobOther = $v; return $this; }

    public function getJobLevelFirstJob(): ?string { return $this->jobLevelFirstJob; }
    public function setJobLevelFirstJob(?string $v): static { $this->jobLevelFirstJob = $v; return $this; }

    public function getJobLevelCurrentJob(): ?string { return $this->jobLevelCurrentJob; }
    public function setJobLevelCurrentJob(?string $v): static { $this->jobLevelCurrentJob = $v; return $this; }

    public function getInitialMonthlyEarning(): ?string { return $this->initialMonthlyEarning; }
    public function setInitialMonthlyEarning(?string $v): static { $this->initialMonthlyEarning = $v; return $this; }

    public function isCurriculumRelevant(): ?bool { return $this->curriculumRelevant; }
    public function setCurriculumRelevant(?bool $v): static { $this->curriculumRelevant = $v; return $this; }

    public function getCompetenciesUseful(): ?array { return $this->competenciesUseful; }
    public function setCompetenciesUseful(?array $v): static { $this->competenciesUseful = $v; return $this; }

    public function getCompetenciesUsefulOther(): ?string { return $this->competenciesUsefulOther; }
    public function setCompetenciesUsefulOther(?string $v): static { $this->competenciesUsefulOther = $v; return $this; }

    public function getSuggestions(): ?string { return $this->suggestions; }
    public function setSuggestions(?string $v): static { $this->suggestions = $v; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }

    public function validateSurveyOwnerRole(ExecutionContextInterface $context): void
    {
        if ($this->user === null) {
            return;
        }

        $roles = $this->user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_STAFF', $roles, true)) {
            $context->buildViolation('Surveys are for Alumni accounts only.')
                ->atPath('user')
                ->addViolation();
        }
    }
}
