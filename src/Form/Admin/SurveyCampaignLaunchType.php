<?php

namespace App\Form\Admin;

use App\Entity\SurveyCampaign;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class SurveyCampaignLaunchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $minimumScheduledSendAt = (new \DateTimeImmutable('now'))->modify('+1 minute');

        $batchChoices = [];
        foreach ($options['batch_years'] as $batchYear) {
            $batchChoices[(string) $batchYear] = (int) $batchYear;
        }

        $collegeChoices = [];
        foreach ($options['colleges'] as $college) {
            $trimmed = trim((string) $college);
            if ($trimmed === '') {
                continue;
            }
            $collegeChoices[$trimmed] = $trimmed;
        }

        $courseChoices = [];
        foreach ($options['courses'] as $course) {
            $trimmed = trim((string) $course);
            if ($trimmed === '') {
                continue;
            }
            $courseChoices[$trimmed] = $trimmed;
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'Campaign Name',
            ])
            ->add('targetBatchYear', ChoiceType::class, [
                'label' => 'Target Batch',
                'mapped' => false,
                'choices' => $batchChoices,
                'placeholder' => 'Select a batch year',
                'required' => true,
                'disabled' => $batchChoices === [],
            ])
            ->add('targetCollege', ChoiceType::class, [
                'label' => 'College (optional)',
                'choices' => $collegeChoices,
                'placeholder' => 'All colleges',
                'required' => false,
            ])
            ->add('targetCourse', ChoiceType::class, [
                'label' => 'Course (optional)',
                'choices' => $courseChoices,
                'placeholder' => 'All courses',
                'required' => false,
            ])
            ->add('emailSubject', TextType::class, [
                'label' => 'Email Subject',
            ])
            ->add('emailBody', TextareaType::class, [
                'label' => 'Email Body',
                'attr' => ['rows' => 9],
            ])
            ->add('scheduledSendAt', DateTimeType::class, [
                'label' => 'Send On (optional)',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'html5' => true,
                'attr' => [
                    'min' => $minimumScheduledSendAt->format('Y-m-d\TH:i'),
                    'step' => 60,
                ],
                'constraints' => [
                    new Assert\GreaterThan([
                        'value' => 'now',
                        'message' => 'Please choose a future send date, or use Send Now.',
                    ]),
                ],
            ])
            ->add('expiryDays', IntegerType::class, [
                'label' => 'Invitation Expiry (days)',
                'attr' => ['min' => 1, 'max' => 180],
            ])
            ->add('preview', SubmitType::class, [
                'label' => 'Preview Recipients',
            ])
            ->add('schedule', SubmitType::class, [
                'label' => 'Schedule Campaign',
            ])
            ->add('send', SubmitType::class, [
                'label' => 'Send Now',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SurveyCampaign::class,
            'batch_years' => [],
            'colleges' => [],
            'courses' => [],
        ]);

        $resolver->setAllowedTypes('batch_years', 'array');
        $resolver->setAllowedTypes('colleges', 'array');
        $resolver->setAllowedTypes('courses', 'array');
    }
}
