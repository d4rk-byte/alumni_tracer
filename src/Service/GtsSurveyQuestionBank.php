<?php

namespace App\Service;

use App\Entity\GtsSurvey;
use App\Entity\GtsSurveyQuestion;
use App\Entity\GtsSurveyTemplate;
use Doctrine\ORM\EntityManagerInterface;

class GtsSurveyQuestionBank
{
    public const INPUT_TEXT = 'text';
    public const INPUT_TEXTAREA = 'textarea';
    public const INPUT_RADIO = 'radio';
    public const INPUT_CHECKBOX = 'checkbox';
    public const INPUT_SELECT = 'select';
    public const INPUT_DATE = 'date';
    public const INPUT_REPEATER = 'repeater';

    /**
     * @param list<GtsSurveyQuestion> $questions
     *
     * @return list<array<string, mixed>>
     */
    public function createRuntimeQuestions(array $questions): array
    {
        if ($questions === []) {
            return $this->getDefaultTemplate();
        }

        return array_map(function (GtsSurveyQuestion $question): array {
            $inputType = $this->normalizeInputType($question->getInputType());

            return [
                'key' => (string) $question->getId(),
                'section' => trim($question->getSection()) !== '' ? trim($question->getSection()) : 'Questionnaire',
                'questionText' => trim($question->getQuestionText()),
                'inputType' => $inputType,
                'options' => $this->normalizeOptions($inputType, $question->getOptions()),
                'sortOrder' => $question->getSortOrder(),
                'numberKey' => $this->extractNumberKey($question->getQuestionText()),
            ];
        }, $questions);
    }

    /**
     * @param array<string, mixed> $submittedAnswers
     * @param list<array<string, mixed>> $runtimeQuestions
     *
     * @return array<string, mixed>
     */
    public function createResponseSnapshot(array $submittedAnswers, array $runtimeQuestions): array
    {
        $responses = [];

        foreach ($runtimeQuestions as $question) {
            $key = (string) ($question['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $inputType = $this->normalizeInputType((string) ($question['inputType'] ?? self::INPUT_TEXT));
            $normalizedAnswer = $this->normalizeAnswer(
                $submittedAnswers[$key] ?? null,
                $inputType,
                is_array($question['options'] ?? null) ? $question['options'] : null,
            );

            if ($this->isEmptyAnswer($normalizedAnswer)) {
                continue;
            }

            $responses[] = [
                'key' => $key,
                'section' => (string) ($question['section'] ?? 'Questionnaire'),
                'questionText' => (string) ($question['questionText'] ?? ''),
                'inputType' => $inputType,
                'options' => $question['options'] ?? null,
                'numberKey' => $question['numberKey'] ?? $this->extractNumberKey((string) ($question['questionText'] ?? '')),
                'answer' => $normalizedAnswer,
            ];
        }

        return [
            'version' => 2,
            'responses' => $responses,
        ];
    }

    /**
     * @param array<string, mixed>|null $snapshot
     *
     * @return array<string, mixed>
     */
    public function extractAnswerValues(?array $snapshot): array
    {
        if (!is_array($snapshot)) {
            return [];
        }

        if (isset($snapshot['responses']) && is_array($snapshot['responses'])) {
            $answers = [];

            foreach ($snapshot['responses'] as $response) {
                if (!is_array($response) || !isset($response['key'])) {
                    continue;
                }

                $answers[(string) $response['key']] = $response['answer'] ?? null;
            }

            return $answers;
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     *
     * @return list<array<string, mixed>>
     */
    public function createRuntimeQuestionsFromStoredResponses(?array $snapshot): array
    {
        $questions = [];

        foreach ($this->getStoredResponseItems($snapshot) as $response) {
            $questions[] = [
                'key' => (string) ($response['key'] ?? ''),
                'section' => (string) ($response['section'] ?? 'Questionnaire'),
                'questionText' => (string) ($response['questionText'] ?? ''),
                'inputType' => (string) ($response['inputType'] ?? self::INPUT_TEXT),
                'options' => $response['options'] ?? null,
                'sortOrder' => 0,
                'numberKey' => $response['numberKey'] ?? null,
            ];
        }

        return $questions;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     *
     * @return list<array<string, mixed>>
     */
    public function getStoredResponseItems(?array $snapshot): array
    {
        if (!is_array($snapshot) || !isset($snapshot['responses']) || !is_array($snapshot['responses'])) {
            return [];
        }

        $items = [];

        foreach ($snapshot['responses'] as $response) {
            if (!is_array($response)) {
                continue;
            }

            $inputType = $this->normalizeInputType((string) ($response['inputType'] ?? self::INPUT_TEXT));
            $answer = $response['answer'] ?? null;

            if ($this->isEmptyAnswer($answer)) {
                continue;
            }

            $items[] = [
                'key' => (string) ($response['key'] ?? ''),
                'section' => (string) ($response['section'] ?? 'Questionnaire'),
                'questionText' => (string) ($response['questionText'] ?? ''),
                'inputType' => $inputType,
                'options' => $this->normalizeOptions($inputType, is_array($response['options'] ?? null) ? $response['options'] : null),
                'numberKey' => $response['numberKey'] ?? $this->extractNumberKey((string) ($response['questionText'] ?? '')),
                'answer' => $answer,
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array{title: string, items: list<array<string, mixed>>}>
     */
    public function groupBySection(array $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $section = (string) ($item['section'] ?? 'Questionnaire');
            if (!isset($grouped[$section])) {
                $grouped[$section] = [
                    'title' => $section,
                    'items' => [],
                ];
            }

            $grouped[$section]['items'][] = $item;
        }

        return array_values($grouped);
    }

    /**
     * @return array{contactNumber: string, occupation: string, companyName: string, companyAddress: string}
     */
    public function extractListSummary(GtsSurvey $survey): array
    {
        $responsesByNumberKey = [];

        foreach ($this->getStoredResponseItems($survey->getDynamicAnswers()) as $response) {
            $numberKey = isset($response['numberKey']) ? strtolower((string) $response['numberKey']) : null;
            if ($numberKey === null || $numberKey === '') {
                continue;
            }

            $responsesByNumberKey[$numberKey] = $response['answer'] ?? null;
        }

        $telephone = $this->stringifyAnswer($responsesByNumberKey['4'] ?? null) ?: (string) ($survey->getTelephoneNumber() ?? '');
        $mobile = $this->stringifyAnswer($responsesByNumberKey['5'] ?? null) ?: (string) ($survey->getMobileNumber() ?? '');
        $occupation = $this->stringifyAnswer($responsesByNumberKey['19'] ?? null) ?: (string) ($survey->getPresentOccupation() ?? '');
        $companyBlock = $this->stringifyAnswer($responsesByNumberKey['20a'] ?? null) ?: (string) ($survey->getCompanyNameAddress() ?? '');

        $contactNumber = trim($telephone) !== '' && trim($mobile) !== ''
            ? sprintf('%s / %s', trim($telephone), trim($mobile))
            : trim($telephone . $mobile);

        $companyParts = preg_split('/\r\n|\r|\n/', trim($companyBlock)) ?: [];
        $companyName = trim((string) ($companyParts[0] ?? ''));
        $companyAddress = trim(implode(', ', array_filter(array_slice($companyParts, 1), static fn ($part): bool => trim((string) $part) !== '')));

        return [
            'contactNumber' => $contactNumber,
            'occupation' => trim($occupation),
            'companyName' => $companyName,
            'companyAddress' => $companyAddress,
        ];
    }

    /**
     * @return array{
     *     presentlyEmployed: string,
     *     employmentStatus: string,
     *     occupation: string,
     *     companyName: string,
     *     companyAddress: string
     * }
     */
    public function extractEmploymentSummary(GtsSurvey $survey): array
    {
        $responsesByNumberKey = [];

        foreach ($this->getStoredResponseItems($survey->getDynamicAnswers()) as $response) {
            $numberKey = isset($response['numberKey']) ? strtolower((string) $response['numberKey']) : null;
            if ($numberKey === null || $numberKey === '') {
                continue;
            }

            $responsesByNumberKey[$numberKey] = $response['answer'] ?? null;
        }

        $listSummary = $this->extractListSummary($survey);
        $presentlyEmployed = $this->stringifyAnswer($responsesByNumberKey['16'] ?? null);
        $employmentStatus = $this->stringifyAnswer($responsesByNumberKey['18'] ?? null);

        if ($employmentStatus === '' && $presentlyEmployed !== '') {
            $employmentStatus = match (strtolower($presentlyEmployed)) {
                'yes' => 'Employed',
                'no' => 'Unemployed',
                'never employed' => 'Never Employed',
                default => $presentlyEmployed,
            };
        }

        return [
            'presentlyEmployed' => $presentlyEmployed,
            'employmentStatus' => $employmentStatus,
            'occupation' => $listSummary['occupation'],
            'companyName' => $listSummary['companyName'],
            'companyAddress' => $listSummary['companyAddress'],
        ];
    }

    public function importDefaults(EntityManagerInterface $entityManager, GtsSurveyTemplate $template): int
    {
        $count = 0;

        foreach ($this->getDefaultTemplate() as $definition) {
            $question = new GtsSurveyQuestion();
            $question
                ->setSurveyTemplate($template)
                ->setSection((string) $definition['section'])
                ->setQuestionText((string) $definition['questionText'])
                ->setInputType((string) $definition['inputType'])
                ->setOptions(is_array($definition['options'] ?? null) ? $definition['options'] : null)
                ->setSortOrder((int) ($definition['sortOrder'] ?? 0))
                ->setIsActive(true);

            $entityManager->persist($question);
            ++$count;
        }

        return $count;
    }

    public function parseOptionsCsv(string $inputType, string $rawOptions): ?array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $rawOptions) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            return null;
        }

        if ($this->normalizeInputType($inputType) !== self::INPUT_REPEATER) {
            return $lines;
        }

        $columns = [];

        foreach ($lines as $line) {
            $parts = array_map('trim', explode('|', $line));
            $key = $parts[0] ?? '';
            if ($key === '') {
                continue;
            }

            $columns[] = $this->normalizeRepeaterColumn([
                'key' => $key,
                'label' => $parts[1] ?? $this->humanizeKey($key),
                'type' => $parts[2] ?? self::INPUT_TEXT,
                'options' => $this->parseInlineOptions($parts[3] ?? ''),
            ]);
        }

        return $columns === [] ? null : $columns;
    }

    public function optionsToCsv(string $inputType, ?array $options): string
    {
        if ($options === null || $options === []) {
            return '';
        }

        if ($this->normalizeInputType($inputType) !== self::INPUT_REPEATER) {
            return implode("\n", array_map(static fn ($option): string => (string) $option, $options));
        }

        $lines = [];
        foreach ($this->normalizeOptions(self::INPUT_REPEATER, $options) ?? [] as $column) {
            $parts = [
                (string) ($column['key'] ?? ''),
                (string) ($column['label'] ?? ''),
                (string) ($column['type'] ?? self::INPUT_TEXT),
            ];

            $inlineOptions = is_array($column['options'] ?? null) ? implode(', ', $column['options']) : '';
            if ($inlineOptions !== '') {
                $parts[] = $inlineOptions;
            }

            $lines[] = implode('|', $parts);
        }

        return implode("\n", $lines);
    }

    private function normalizeInputType(string $inputType): string
    {
        $normalized = strtolower(trim($inputType));
        $allowed = [
            self::INPUT_TEXT,
            self::INPUT_TEXTAREA,
            self::INPUT_RADIO,
            self::INPUT_CHECKBOX,
            self::INPUT_SELECT,
            self::INPUT_DATE,
            self::INPUT_REPEATER,
        ];

        return in_array($normalized, $allowed, true) ? $normalized : self::INPUT_TEXT;
    }

    /**
     * @param array<int, mixed>|null $options
     *
     * @return array<int, mixed>|null
     */
    private function normalizeOptions(string $inputType, ?array $options): ?array
    {
        if ($options === null) {
            return null;
        }

        if ($inputType !== self::INPUT_REPEATER) {
            $values = array_values(array_filter(array_map(static function ($option): string {
                return trim((string) $option);
            }, $options), static fn (string $option): bool => $option !== ''));

            return $values === [] ? null : $values;
        }

        $columns = [];
        foreach ($options as $column) {
            if (is_array($column)) {
                $columns[] = $this->normalizeRepeaterColumn($column);
                continue;
            }

            if (is_string($column)) {
                $parts = array_map('trim', explode('|', $column));
                if (($parts[0] ?? '') === '') {
                    continue;
                }

                $columns[] = $this->normalizeRepeaterColumn([
                    'key' => $parts[0],
                    'label' => $parts[1] ?? $this->humanizeKey($parts[0]),
                    'type' => $parts[2] ?? self::INPUT_TEXT,
                    'options' => $this->parseInlineOptions($parts[3] ?? ''),
                ]);
            }
        }

        return $columns === [] ? null : $columns;
    }

    /**
     * @param array<string, mixed> $column
     *
     * @return array<string, mixed>
     */
    private function normalizeRepeaterColumn(array $column): array
    {
        $type = $this->normalizeInputType((string) ($column['type'] ?? self::INPUT_TEXT));
        if ($type === self::INPUT_REPEATER) {
            $type = self::INPUT_TEXT;
        }

        return [
            'key' => (string) ($column['key'] ?? ''),
            'label' => trim((string) ($column['label'] ?? $this->humanizeKey((string) ($column['key'] ?? '')))),
            'type' => $type,
            'options' => $this->normalizeOptions($type, is_array($column['options'] ?? null) ? $column['options'] : $this->parseInlineOptions((string) ($column['options'] ?? ''))),
        ];
    }

    /**
     * @param array<int, mixed>|null $options
     */
    private function normalizeAnswer(mixed $value, string $inputType, ?array $options): mixed
    {
        if ($inputType === self::INPUT_CHECKBOX) {
            if (!is_array($value)) {
                return [];
            }

            return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
        }

        if ($inputType === self::INPUT_REPEATER) {
            if (!is_array($value)) {
                return [];
            }

            $rows = [];
            foreach ($value as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $normalizedRow = [];
                foreach ($options ?? [] as $column) {
                    if (!is_array($column)) {
                        continue;
                    }

                    $columnKey = (string) ($column['key'] ?? '');
                    if ($columnKey === '') {
                        continue;
                    }

                    $columnValue = trim((string) ($row[$columnKey] ?? ''));
                    if ($columnValue !== '') {
                        $normalizedRow[$columnKey] = $columnValue;
                    }
                }

                if ($normalizedRow !== []) {
                    $rows[] = $normalizedRow;
                }
            }

            return $rows;
        }

        if (is_array($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function isEmptyAnswer(mixed $answer): bool
    {
        if ($answer === null) {
            return true;
        }

        if (is_string($answer)) {
            return trim($answer) === '';
        }

        if (!is_array($answer)) {
            return false;
        }

        if ($answer === []) {
            return true;
        }

        foreach ($answer as $item) {
            if (!$this->isEmptyAnswer($item)) {
                return false;
            }
        }

        return true;
    }

    private function stringifyAnswer(mixed $answer): string
    {
        if (is_string($answer)) {
            return trim($answer);
        }

        if (is_array($answer) && $answer !== []) {
            $flat = [];
            array_walk_recursive($answer, static function ($value) use (&$flat): void {
                $stringValue = trim((string) $value);
                if ($stringValue !== '') {
                    $flat[] = $stringValue;
                }
            });

            return implode(', ', $flat);
        }

        return '';
    }

    private function extractNumberKey(string $questionText): ?string
    {
        $trimmed = trim($questionText);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?[a-z]?)/i', $trimmed, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getDefaultTemplate(): array
    {
        $courseReasons = [
            'High grades in the course or subject area(s) related to the course',
            'Good grades in high school',
            'Influence of parents or relatives',
            'Peer influence',
            'Inspired by a role model',
            'Strong passion for the profession',
            'Prospect for immediate employment',
            'Status or prestige of the profession',
            'Availability of course offering in chosen institution',
            'Prospect of career advancement',
            'Affordable for the family',
            'Prospect of attractive compensation',
            'Opportunity for employment abroad',
            'No particular choice or no better idea',
        ];

        return [
            $this->makeQuestion('default-a-02', 'A. General Information', '2. Permanent Address', self::INPUT_TEXTAREA, 20),
            $this->makeQuestion('default-a-04', 'A. General Information', '4. Telephone / Contact Number(s)', self::INPUT_TEXT, 40),
            $this->makeQuestion('default-a-05', 'A. General Information', '5. Mobile Number', self::INPUT_TEXT, 50),
            $this->makeQuestion('default-a-06', 'A. General Information', '6. Civil Status', self::INPUT_SELECT, 60, [
                'Single',
                'Married',
                'Separated / Divorced',
                'Married but not living with spouse',
                'Single Parent',
                'Widow or Widower',
            ]),
            $this->makeQuestion('default-a-07', 'A. General Information', '7. Sex', self::INPUT_RADIO, 70, ['Male', 'Female']),
            $this->makeQuestion('default-a-08', 'A. General Information', '8. Birthday', self::INPUT_DATE, 80),
            $this->makeQuestion('default-a-09', 'A. General Information', '9. Region of Origin', self::INPUT_SELECT, 90, [
                'Region I',
                'Region II',
                'Region III',
                'Region IV',
                'Region V',
                'Region VI',
                'Region VII',
                'Region VIII',
                'Region IX',
                'Region X',
                'Region XI',
                'Region XII',
                'NCR',
                'CAR',
                'ARMM',
                'CARAGA',
            ]),
            $this->makeQuestion('default-a-10', 'A. General Information', '10. Province', self::INPUT_TEXT, 100),
            $this->makeQuestion('default-a-11', 'A. General Information', '11. Location of Residence', self::INPUT_RADIO, 110, ['City', 'Municipality']),

            $this->makeQuestion('default-b-12', 'B. Educational Background', '12. Educational Attainment (Baccalaureate Degree Only)', self::INPUT_REPEATER, 120, [
                $this->makeRepeaterColumn('college', 'College', self::INPUT_TEXT),
                $this->makeRepeaterColumn('yearGraduated', 'Year Graduated', self::INPUT_TEXT),
            ]),
            $this->makeQuestion('default-b-13', 'B. Educational Background', '13. Professional Examination(s) Passed', self::INPUT_REPEATER, 130, [
                $this->makeRepeaterColumn('name', 'Name of Examination', self::INPUT_TEXT),
                $this->makeRepeaterColumn('dateTaken', 'Date Taken', self::INPUT_DATE),
                $this->makeRepeaterColumn('rating', 'Rating', self::INPUT_SELECT, ['Passed', 'Failed', 'Pending']),
            ]),
            $this->makeQuestion('default-b-14-undergrad', 'B. Educational Background', '14. Reason(s) for taking the course(s) or pursuing degree(s) - Undergraduate / AB / BS', self::INPUT_CHECKBOX, 140, $courseReasons),
            $this->makeQuestion('default-b-14-grad', 'B. Educational Background', '14. Reason(s) for taking the course(s) or pursuing degree(s) - Graduate / MS / MA / Ph.D.', self::INPUT_CHECKBOX, 141, $courseReasons),
            $this->makeQuestion('default-b-14-other', 'B. Educational Background', '14. Other reason(s)', self::INPUT_TEXT, 142),

            $this->makeQuestion('default-c-15a', 'C. Training / Advance Studies', '15a. Professional or work-related training program(s) including advance studies', self::INPUT_REPEATER, 150, [
                $this->makeRepeaterColumn('title', 'Title of Training / Advance Study', self::INPUT_TEXT),
                $this->makeRepeaterColumn('duration', 'Duration & Credits Earned', self::INPUT_TEXT),
                $this->makeRepeaterColumn('institution', 'Training Institution', self::INPUT_TEXT),
            ]),
            $this->makeQuestion('default-c-certifications', 'C. Training / Advance Studies', '15a.1 Certifications', self::INPUT_REPEATER, 151, [
                $this->makeRepeaterColumn('name', 'Certification', self::INPUT_TEXT),
            ]),
            $this->makeQuestion('default-c-15b', 'C. Training / Advance Studies', '15b. What made you pursue advance studies?', self::INPUT_CHECKBOX, 152, [
                'For promotion',
                'For professional development',
            ]),
            $this->makeQuestion('default-c-15b-other', 'C. Training / Advance Studies', '15b. Other reason(s)', self::INPUT_TEXT, 153),

            $this->makeQuestion('default-d-16', 'D. Employment Data', '16. Are you presently employed?', self::INPUT_RADIO, 160, ['Yes', 'No', 'Never Employed']),
            $this->makeQuestion('default-d-17', 'D. Employment Data', '17. Reason(s) why you are not yet employed', self::INPUT_CHECKBOX, 170, [
                'Advance or further study',
                'Family concern and decided not to find a job',
                'Health-related reason(s)',
                'Lack of work experience',
                'No job opportunity',
                'Did not look for a job',
            ]),
            $this->makeQuestion('default-d-17-other', 'D. Employment Data', '17. Other reason(s)', self::INPUT_TEXT, 171),
            $this->makeQuestion('default-d-18', 'D. Employment Data', '18. Present Employment Status', self::INPUT_SELECT, 180, [
                'Regular or Permanent',
                'Temporary',
                'Casual',
                'Contractual',
                'Self-employed',
            ]),
            $this->makeQuestion('default-d-19', 'D. Employment Data', '19. Present Occupation (PSOC Classification)', self::INPUT_SELECT, 190, [
                'Officials of Government & Corporate Executives, Managers',
                'Professionals',
                'Technicians and Associate Professionals',
                'Clerks',
                'Service Workers and Shop & Market Sales Workers',
                'Farmers, Forestry Workers and Fishermen',
                'Trades and Related Workers',
                'Plant and Machine Operators and Assemblers',
                'Laborers and Unskilled Workers',
                'Special Occupation',
            ]),
            $this->makeQuestion('default-d-20a', 'D. Employment Data', '20a. Name of Company / Organization (including address)', self::INPUT_TEXTAREA, 200),
            $this->makeQuestion('default-d-20b', 'D. Employment Data', '20b. Major Line of Business', self::INPUT_SELECT, 201, [
                'Agriculture, Hunting and Forestry',
                'Fishing',
                'Mining and Quarrying',
                'Manufacturing',
                'Electricity, Gas and Water Supply',
                'Construction',
                'Wholesale and Retail Trade',
                'Hotels and Restaurants',
                'Transport, Storage and Communication',
                'Financial Intermediation',
                'Real Estate, Renting and Business Activities',
                'Public Administration and Defense',
                'Education',
                'Health and Social Work',
                'Other Community, Social and Personal Service Activities',
                'Private Households with Employed Persons',
                'Extra-territorial Organizations and Bodies',
            ]),
            $this->makeQuestion('default-d-21', 'D. Employment Data', '21. Place of Work', self::INPUT_RADIO, 210, ['Local', 'Abroad']),
            $this->makeQuestion('default-d-22', 'D. Employment Data', '22. Is this your first job after college?', self::INPUT_RADIO, 220, ['Yes', 'No']),
            $this->makeQuestion('default-d-23', 'D. Employment Data', '23. Reason(s) for staying on the job', self::INPUT_CHECKBOX, 230, [
                'Salaries and benefits',
                'Career challenge',
                'Related to special skill',
                'Related to course or program of study',
                'Proximity to residence',
                'Peer influence',
                'Family influence',
            ]),
            $this->makeQuestion('default-d-23-other', 'D. Employment Data', '23. Other reason(s) for staying on the job', self::INPUT_TEXT, 231),
            $this->makeQuestion('default-d-24', 'D. Employment Data', '24. Is your first job related to the course you took up in college?', self::INPUT_RADIO, 240, ['Yes', 'No']),
            $this->makeQuestion('default-d-25', 'D. Employment Data', '25. Reason(s) for accepting the job', self::INPUT_CHECKBOX, 250, [
                'Salaries and benefits',
                'Career challenge',
                'Related to special skills',
                'Proximity to residence',
            ]),
            $this->makeQuestion('default-d-25-other', 'D. Employment Data', '25. Other reason(s) for accepting the job', self::INPUT_TEXT, 251),
            $this->makeQuestion('default-d-26', 'D. Employment Data', '26. Reason(s) for changing job', self::INPUT_CHECKBOX, 260, [
                'Salaries and benefits',
                'Career challenge',
                'Related to special skills',
                'Proximity to residence',
            ]),
            $this->makeQuestion('default-d-26-other', 'D. Employment Data', '26. Other reason(s) for changing job', self::INPUT_TEXT, 261),
            $this->makeQuestion('default-d-27', 'D. Employment Data', '27. How long did you stay in your first job?', self::INPUT_SELECT, 270, [
                'Less than a month',
                '1 to 6 months',
                '7 to 11 months',
                '1 year to less than 2 years',
                '2 years to less than 3 years',
                '3 years to less than 4 years',
            ]),
            $this->makeQuestion('default-d-27-other', 'D. Employment Data', '27. Other duration in first job', self::INPUT_TEXT, 271),
            $this->makeQuestion('default-d-28', 'D. Employment Data', '28. How did you find your first job?', self::INPUT_CHECKBOX, 280, [
                'Response to an advertisement',
                'As walk-in applicant',
                'Recommended by someone',
                'Information from friends',
                'Arranged by school\'s job placement officer',
                'Family business',
                'Job Fair or PESO',
            ]),
            $this->makeQuestion('default-d-28-other', 'D. Employment Data', '28. Other way you found your first job', self::INPUT_TEXT, 281),
            $this->makeQuestion('default-d-29', 'D. Employment Data', '29. How long did it take you to land your first job?', self::INPUT_SELECT, 290, [
                'Less than a month',
                '1 to 6 months',
                '7 to 11 months',
                '1 year to less than 2 years',
                '2 years to less than 3 years',
                '3 years to less than 4 years',
            ]),
            $this->makeQuestion('default-d-29-other', 'D. Employment Data', '29. Other time to land first job', self::INPUT_TEXT, 291),
            $this->makeQuestion('default-d-30-1', 'D. Employment Data', '30.1 Job Level - First Job', self::INPUT_SELECT, 301, [
                'Rank or Clerical',
                'Professional, Technical or Supervisory',
                'Managerial or Executive',
                'Self-employed',
            ]),
            $this->makeQuestion('default-d-30-2', 'D. Employment Data', '30.2 Job Level - Current / Present Job', self::INPUT_SELECT, 302, [
                'Rank or Clerical',
                'Professional, Technical or Supervisory',
                'Managerial or Executive',
                'Self-employed',
            ]),
            $this->makeQuestion('default-d-31', 'D. Employment Data', '31. Initial Gross Monthly Earning in First Job', self::INPUT_SELECT, 310, [
                'Below PHP 5,000',
                'PHP 5,000 to less than PHP 10,000',
                'PHP 10,000 to less than PHP 15,000',
                'PHP 15,000 to less than PHP 20,000',
                'PHP 20,000 to less than PHP 25,000',
                'PHP 25,000 and above',
            ]),
            $this->makeQuestion('default-d-32', 'D. Employment Data', '32. Was the curriculum relevant to your first job?', self::INPUT_RADIO, 320, ['Yes', 'No']),
            $this->makeQuestion('default-d-33', 'D. Employment Data', '33. Competencies learned in college useful in first job', self::INPUT_CHECKBOX, 330, [
                'Communication skills',
                'Human Relations skills',
                'Entrepreneurial skills',
                'Problem-solving skills',
                'Critical Thinking skills',
            ]),
            $this->makeQuestion('default-d-33-other', 'D. Employment Data', '33. Other useful skills', self::INPUT_TEXT, 331),
            $this->makeQuestion('default-d-34', 'D. Employment Data', '34. Suggestions to further improve your course curriculum', self::INPUT_TEXTAREA, 340),
        ];
    }

    /**
     * @param array<int, string>|null $options
     *
     * @return array<string, mixed>
     */
    private function makeQuestion(string $key, string $section, string $questionText, string $inputType, int $sortOrder, ?array $options = null): array
    {
        return [
            'key' => $key,
            'section' => $section,
            'questionText' => $questionText,
            'inputType' => $inputType,
            'options' => $options,
            'sortOrder' => $sortOrder,
            'numberKey' => $this->extractNumberKey($questionText),
        ];
    }

    /**
     * @param array<int, string>|null $options
     *
     * @return array<string, mixed>
     */
    private function makeRepeaterColumn(string $key, string $label, string $type, ?array $options = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'options' => $options,
        ];
    }

    /**
     * @return list<string>|null
     */
    private function parseInlineOptions(string $value): ?array
    {
        $options = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $option): bool => $option !== ''));

        return $options === [] ? null : $options;
    }

    private function humanizeKey(string $key): string
    {
        $humanized = trim(str_replace(['_', '-'], ' ', $key));

        return $humanized === '' ? 'Field' : ucwords($humanized);
    }
}
