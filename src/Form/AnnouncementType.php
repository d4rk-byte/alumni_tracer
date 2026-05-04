<?php

namespace App\Form;

use App\Entity\Announcement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AnnouncementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'attr' => ['class' => 'form-input'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'attr' => ['class' => 'form-input', 'rows' => 5],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Category',
                'required' => false,
                'placeholder' => '— Select —',
                'choices' => [
                    'General' => 'General',
                    'Job Opportunity' => 'Job Opportunity',
                    'Event' => 'Event',
                    'University News' => 'University News',
                    'Seminar' => 'Seminar',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('eventStartAt', DateTimeType::class, [
                'label' => 'Event Date and Time',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('location', TextType::class, [
                'label' => 'Location',
                'required' => false,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Campus, venue, or online',
                ],
            ])
            ->add('joinUrl', TextType::class, [
                'label' => 'Link',
                'required' => false,
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'https://example.com/event-or-form',
                ],
                'help' => 'Optional. Add any event, form, meeting, or reference link.',
            ])
            ->add('isActive', ChoiceType::class, [
                'label' => 'Status',
                'choices' => ['Active' => true, 'Draft / Inactive' => false],
                'attr' => ['class' => 'form-select'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Announcement::class,
        ]);
    }
}
