<?php

namespace App\Form\Admin;

use App\Entity\GtsSurveyQuestion;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GtsSurveyQuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('questionText', TextareaType::class, [
                'label' => 'Question Text',
                'attr' => [
                    'rows' => 3,
                    'class' => 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200',
                ],
            ])
            ->add('inputType', ChoiceType::class, [
                'label' => 'Input Type',
                'choices' => [
                    'Text' => 'text',
                    'Textarea' => 'textarea',
                    'Radio' => 'radio',
                    'Checkbox' => 'checkbox',
                    'Select' => 'select',
                    'Date' => 'date',
                    'Repeater Rows' => 'repeater',
                ],
                'attr' => [
                    'class' => 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200',
                ],
            ])
            ->add('section', TextType::class, [
                'label' => 'Section',
                'attr' => [
                    'placeholder' => 'e.g. General Information',
                    'class' => 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200',
                ],
            ])
            ->add('optionsCsv', TextareaType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Choices / Columns',
                'attr' => [
                    'rows' => 5,
                    'class' => 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200',
                    'placeholder' => "Choice A\nChoice B\nChoice C\n\nFor repeater rows:\nfield_key|Field Label|text\nstatus|Status|select|Passed, Failed, Pending",
                ],
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'Sort Order',
                'attr' => [
                    'class' => 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200',
                ],
            ])
            ->add('isActive', CheckboxType::class, [
                'required' => false,
                'label' => 'Active question',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GtsSurveyQuestion::class,
        ]);
    }
}
