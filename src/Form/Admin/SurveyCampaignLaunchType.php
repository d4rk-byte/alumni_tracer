<?php

namespace App\Form\Admin;

use App\Entity\SurveyCampaign;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SurveyCampaignLaunchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $yearChoices = [];
        foreach ($options['years'] as $year) {
            $yearChoices[(string) $year] = (int) $year;
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
            ->add('targetYear', ChoiceType::class, [
                'label' => 'Graduation Year',
                'mapped' => false,
                'choices' => $yearChoices,
                'placeholder' => 'Select a graduation year',
                'required' => true,
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
            ->add('expiryDays', IntegerType::class, [
                'label' => 'Invitation Expiry (days)',
                'attr' => ['min' => 1, 'max' => 180],
            ])
            ->add('preview', SubmitType::class, [
                'label' => 'Preview Recipients',
            ])
            ->add('send', SubmitType::class, [
                'label' => 'Create Campaign',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SurveyCampaign::class,
            'years' => [],
            'colleges' => [],
            'courses' => [],
        ]);

        $resolver->setAllowedTypes('years', 'array');
        $resolver->setAllowedTypes('colleges', 'array');
        $resolver->setAllowedTypes('courses', 'array');
    }
}
