<?php

namespace App\Form;

use App\Entity\College;
use App\Entity\Department;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class QrRegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('college', ChoiceType::class, [
                'label' => 'College',
                'mapped' => false,
                'choices' => $options['college_choices'],
                'choice_label' => static fn (College $college): string => $college->getName(),
                'choice_value' => static fn (?College $college): string => $college?->getId() !== null ? (string) $college->getId() : '',
                'placeholder' => '— Select College —',
                'constraints' => [
                    new NotBlank(message: 'Please select your college.'),
                ],
            ])
            ->add('department', ChoiceType::class, [
                'label' => 'Department',
                'mapped' => false,
                'choices' => $options['department_choices'],
                'choice_label' => static fn (Department $department): string => sprintf('%s (%s)', $department->getName(), $department->getCode()),
                'choice_value' => static fn (?Department $department): string => $department?->getId() !== null ? (string) $department->getId() : '',
                'choice_attr' => static fn (Department $department): array => [
                    'data-college-id' => (string) $department->getCollege()?->getId(),
                    'data-department-code' => $department->getCode(),
                ],
                'placeholder' => '— Select Department —',
                'constraints' => [
                    new NotBlank(message: 'Please select your department.'),
                ],
            ])
            ->add('studentId', TextType::class, [
                'label' => 'Student ID',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(message: 'Please enter your student ID.'),
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'First Name',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(message: 'Please enter your first name.'),
                ],
            ])
            ->add('middleName', TextType::class, [
                'label' => 'Middle Name',
                'mapped' => false,
                'required' => false,
                'empty_data' => '',
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last Name',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(message: 'Please enter your last name.'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(message: 'Please enter your email address.'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'Password',
                    'constraints' => [
                        new NotBlank(message: 'Please enter a password'),
                        new Length(
                            min: 8,
                            minMessage: 'Your password should be at least {{ limit }} characters',
                            max: 4096,
                        ),
                        new Regex(
                            pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).+$/',
                            message: 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
                        ),
                    ],
                ],
                'second_options' => [
                    'label' => 'Repeat Password',
                ],
                'invalid_message' => 'The password fields must match.',
            ])
            ->add('dataPrivacyConsent', CheckboxType::class, [
                'mapped' => false,
                'label' => 'I agree to the data privacy terms for account processing.',
                'constraints' => [
                    new IsTrue(message: 'You must agree to the data privacy terms.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'college_choices' => [],
            'department_choices' => [],
        ]);

        $resolver->setAllowedTypes('college_choices', 'array');
        $resolver->setAllowedTypes('department_choices', 'array');
    }
}