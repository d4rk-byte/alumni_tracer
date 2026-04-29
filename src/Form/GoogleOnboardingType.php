<?php

namespace App\Form;

use App\Entity\College;
use App\Repository\CollegeRepository;
use App\Repository\DepartmentRepository;
use App\Repository\QrRegistrationBatchRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class GoogleOnboardingType extends AbstractType
{
    public function __construct(
        private CollegeRepository $collegeRepository,
        private DepartmentRepository $departmentRepository,
        private QrRegistrationBatchRepository $qrRegistrationBatchRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $data = $builder->getData();
        $currentBatchYear = is_array($data) && isset($data['yearGraduated'])
            ? (int) $data['yearGraduated']
            : null;
        $currentCollege = is_array($data) ? trim((string) ($data['college'] ?? '')) : '';
        $currentDepartment = is_array($data) ? trim((string) ($data['department'] ?? '')) : '';

        $batchChoices = $this->buildBatchChoices($currentBatchYear);
        $collegeChoices = $this->buildCollegeChoices($currentCollege);
        [$departmentChoices, $departmentMetaByName] = $this->buildDepartmentChoices($currentDepartment);

        $builder
            ->add('schoolId', TextType::class, [
                'label' => 'School ID',
                'attr' => ['class' => 'form-input', 'placeholder' => 'e.g. 2022-00123'],
                'constraints' => [new NotBlank(message: 'Please enter your school ID.')],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'First Name',
                'attr' => ['class' => 'form-input', 'placeholder' => 'First Name'],
                'constraints' => [new NotBlank(message: 'Please enter your first name.')],
            ])
            ->add('middleName', TextType::class, [
                'label' => 'Middle Name',
                'required' => false,
                'attr' => ['class' => 'form-input', 'placeholder' => 'Middle Name'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last Name',
                'attr' => ['class' => 'form-input', 'placeholder' => 'Last Name'],
                'constraints' => [new NotBlank(message: 'Please enter your last name.')],
            ])
            ->add('yearGraduated', ChoiceType::class, [
                'label' => 'Batch Year',
                'placeholder' => '— Select Batch Year —',
                'choices' => $batchChoices,
                'attr' => ['class' => 'form-select'],
                'constraints' => [
                    new NotBlank(message: 'Please enter your batch year.'),
                ],
            ])
            ->add('college', ChoiceType::class, [
                'label' => 'College',
                'placeholder' => '— Select College —',
                'choices' => $collegeChoices,
                'attr' => [
                    'class' => 'form-select',
                    'data-google-college' => 'true',
                ],
                'constraints' => [new NotBlank(message: 'Please select your college.')],
            ])
            ->add('department', ChoiceType::class, [
                'label' => 'Department',
                'placeholder' => '— Select Department —',
                'choices' => $departmentChoices,
                'attr' => [
                    'class' => 'form-select',
                    'data-google-department' => 'true',
                ],
                'choice_attr' => static function (?string $choice, string $key, mixed $value) use ($departmentMetaByName): array {
                    if ($choice === null || !isset($departmentMetaByName[$choice])) {
                        return [];
                    }

                    return [
                        'data-college-name' => $departmentMetaByName[$choice]['college'],
                        'data-course-code' => $departmentMetaByName[$choice]['code'],
                    ];
                },
                'constraints' => [new NotBlank(message: 'Please select your department.')],
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($departmentMetaByName): void {
            $data = $event->getData();

            if (!is_array($data)) {
                return;
            }

            $department = trim((string) ($data['department'] ?? ''));
            if ($department === '' || !isset($departmentMetaByName[$department])) {
                return;
            }

            $data['college'] = $departmentMetaByName[$department]['college'];

            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function buildBatchChoices(?int $currentBatchYear): array
    {
        $choices = [];

        foreach ($this->qrRegistrationBatchRepository->findAllOrdered() as $batch) {
            $batchYear = $batch->getBatchYear();
            $choices[(string) $batchYear] = $batchYear;
        }

        if ($currentBatchYear !== null && $currentBatchYear > 0 && !in_array($currentBatchYear, $choices, true)) {
            $choices[(string) $currentBatchYear . ' (current)'] = $currentBatchYear;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function buildCollegeChoices(string $currentCollege): array
    {
        $choices = [];

        foreach ($this->collegeRepository->findActive() as $college) {
            $choices[$college->getName()] = $college->getName();
        }

        if ($currentCollege !== '' && !in_array($currentCollege, $choices, true)) {
            $choices[$currentCollege . ' (current)'] = $currentCollege;
        }

        return $choices;
    }

    /**
     * @return array{0: array<string, array<string, string>>, 1: array<string, array{college: string, code: string}>}
     */
    private function buildDepartmentChoices(string $currentDepartment): array
    {
        $choices = [];
        $meta = [];

        foreach ($this->departmentRepository->findActiveWithActiveCollege() as $department) {
            $college = $department->getCollege();
            $collegeName = $college?->getName() ?? 'Unassigned';
            $departmentName = $department->getName();

            $choices[$collegeName][$departmentName] = $departmentName;
            $meta[$departmentName] = [
                'college' => $collegeName,
                'code' => $department->getCode(),
            ];
        }

        if ($currentDepartment !== '' && !isset($meta[$currentDepartment])) {
            $choices['Current Value'][$currentDepartment . ' (current)'] = $currentDepartment;
            $meta[$currentDepartment] = [
                'college' => '',
                'code' => '',
            ];
        }

        return [$choices, $meta];
    }
}