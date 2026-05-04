<?php

namespace App\Service;

use App\Entity\GtsSurvey;
use App\Repository\GtsSurveyRepository;

class GtsSurveyAnalyticsService
{
    public function __construct(
        private GtsSurveyRepository $surveyRepository,
        private GtsSurveyQuestionBank $questionBank,
    ) {
    }

    /**
     * @return array{
     *     responseCount: int,
     *     answeredQuestionCount: int,
     *     employmentRate: float,
     *     courseAlignmentRate: float,
     *     curriculumRelevanceRate: float,
     *     presentlyEmployed: list<array{label: string, total: int}>,
     *     presentEmploymentStatus: list<array{label: string, total: int}>,
     *     placeOfWork: list<array{label: string, total: int}>,
     *     firstJobRelatedToCourse: list<array{label: string, total: int}>,
     *     curriculumRelevant: list<array{label: string, total: int}>,
     *     salaryRanges: list<array{label: string, total: int}>,
     *     competencies: list<array{label: string, total: int}>,
     *     responsesByBatch: list<array{label: string, total: int}>
     * }
     */
    public function summarize(): array
    {
        $presentlyEmployed = [];
        $presentEmploymentStatus = [];
        $placeOfWork = [];
        $firstJobRelatedToCourse = [];
        $curriculumRelevant = [];
        $salaryRanges = [];
        $competencies = [];
        $responsesByBatch = [];
        $answeredQuestionCount = 0;
        $responseCount = 0;

        foreach ($this->surveyRepository->findAll() as $survey) {
            if (!$survey instanceof GtsSurvey) {
                continue;
            }

            ++$responseCount;
            $answersByNumber = $this->answersByNumberKey($survey);
            $answeredQuestionCount += count($answersByNumber);

            $this->increment($responsesByBatch, $this->resolveBatchLabel($survey));
            $this->increment($presentlyEmployed, $this->stringAnswer($answersByNumber['16'] ?? $survey->getPresentlyEmployed()));
            $this->increment($presentEmploymentStatus, $this->stringAnswer($answersByNumber['18'] ?? $survey->getPresentEmploymentStatus()));
            $this->increment($placeOfWork, $this->stringAnswer($answersByNumber['21'] ?? $survey->getPlaceOfWork()));
            $this->increment($firstJobRelatedToCourse, $this->stringAnswer($answersByNumber['24'] ?? $this->boolAnswer($survey->isFirstJobRelatedToCourse())));
            $this->increment($curriculumRelevant, $this->stringAnswer($answersByNumber['32'] ?? $this->boolAnswer($survey->isCurriculumRelevant())));
            $this->increment($salaryRanges, $this->stringAnswer($answersByNumber['31'] ?? $survey->getInitialMonthlyEarning()));

            $competencyAnswer = $answersByNumber['33'] ?? $survey->getCompetenciesUseful();
            foreach ($this->answerValues($competencyAnswer) as $competency) {
                $this->increment($competencies, $competency);
            }
        }

        return [
            'responseCount' => $responseCount,
            'answeredQuestionCount' => $answeredQuestionCount,
            'employmentRate' => $this->yesRate($presentlyEmployed),
            'courseAlignmentRate' => $this->yesRate($firstJobRelatedToCourse),
            'curriculumRelevanceRate' => $this->yesRate($curriculumRelevant),
            'presentlyEmployed' => $this->chartRows($presentlyEmployed),
            'presentEmploymentStatus' => $this->chartRows($presentEmploymentStatus),
            'placeOfWork' => $this->chartRows($placeOfWork),
            'firstJobRelatedToCourse' => $this->chartRows($firstJobRelatedToCourse),
            'curriculumRelevant' => $this->chartRows($curriculumRelevant),
            'salaryRanges' => $this->chartRows($salaryRanges),
            'competencies' => $this->chartRows($competencies),
            'responsesByBatch' => $this->chartRows($responsesByBatch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function answersByNumberKey(GtsSurvey $survey): array
    {
        $answers = [];

        foreach ($this->questionBank->getStoredResponseItems($survey->getDynamicAnswers()) as $response) {
            $numberKey = strtolower(trim((string) ($response['numberKey'] ?? '')));
            if ($numberKey === '') {
                $numberKey = $this->inferNumberKey((string) ($response['questionText'] ?? ''));
            }

            if ($numberKey === '') {
                continue;
            }

            $answers[$numberKey] = $response['answer'] ?? null;
        }

        return $answers;
    }

    private function inferNumberKey(string $questionText): string
    {
        $normalized = strtolower(trim($questionText));
        if ($normalized === '') {
            return '';
        }

        if (str_contains($normalized, 'presently employed')) {
            return '16';
        }

        if (str_contains($normalized, 'present employment status')) {
            return '18';
        }

        if (str_contains($normalized, 'place of work')) {
            return '21';
        }

        if (str_contains($normalized, 'related to the course')) {
            return '24';
        }

        if (str_contains($normalized, 'initial gross monthly earning')) {
            return '31';
        }

        if (str_contains($normalized, 'curriculum relevant')) {
            return '32';
        }

        if (str_contains($normalized, 'competencies')) {
            return '33';
        }

        return '';
    }

    /**
     * @param array<string, int> $counts
     */
    private function increment(array &$counts, ?string $label): void
    {
        $label = trim((string) $label);
        if ($label === '') {
            return;
        }

        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }

    private function resolveBatchLabel(GtsSurvey $survey): string
    {
        $batchYear = $survey->getSurveyInvitation()?->getCampaign()->getTargetBatchYear()
            ?? $survey->getUser()?->getAlumni()?->getYearGraduated();

        return $batchYear !== null ? 'Batch ' . $batchYear : 'Unknown batch';
    }

    private function stringAnswer(mixed $answer): ?string
    {
        if (is_bool($answer)) {
            return $this->boolAnswer($answer);
        }

        if (is_string($answer) || is_numeric($answer)) {
            $value = trim((string) $answer);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function boolAnswer(?bool $answer): ?string
    {
        return $answer === null ? null : ($answer ? 'Yes' : 'No');
    }

    /**
     * @return list<string>
     */
    private function answerValues(mixed $answer): array
    {
        if (is_string($answer) || is_numeric($answer)) {
            $value = trim((string) $answer);

            return $value !== '' ? [$value] : [];
        }

        if (!is_array($answer)) {
            return [];
        }

        $values = [];
        array_walk_recursive($answer, static function (mixed $value) use (&$values): void {
            $stringValue = trim((string) $value);
            if ($stringValue !== '') {
                $values[] = $stringValue;
            }
        });

        return $values;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{label: string, total: int}>
     */
    private function chartRows(array $counts): array
    {
        arsort($counts);

        return array_map(
            static fn (string $label, int $total): array => ['label' => $label, 'total' => $total],
            array_keys($counts),
            array_values($counts),
        );
    }

    /**
     * @param array<string, int> $counts
     */
    private function yesRate(array $counts): float
    {
        $total = array_sum($counts);
        if ($total === 0) {
            return 0.0;
        }

        return round((($counts['Yes'] ?? 0) / $total) * 100, 1);
    }
}
