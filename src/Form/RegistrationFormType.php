<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('schoolId', TextType::class, [
                'attr' => ['class' => 'form-input', 'placeholder' => 'e.g. 2022-00123'],
                'label' => 'School ID',
                'constraints' => [
                    new NotBlank(message: 'Please enter your student ID.'),
                ],
            ])
            ->add('yearGraduated', IntegerType::class, [
                'mapped' => false,
                'attr' => ['class' => 'form-input', 'placeholder' => 'e.g. 2022', 'min' => 1950, 'max' => (string) ((int) date('Y') + 10)],
                'label' => 'Batch Year',
                'constraints' => [
                    new NotBlank(message: 'Please enter your batch year.'),
                    new Range(
                        min: 1950,
                        max: (int) date('Y') + 10,
                        notInRangeMessage: 'Batch year must be between {{ min }} and {{ max }}.',
                    ),
                ],
            ])
            ->add('firstName', TextType::class, [
                'attr' => ['class' => 'form-input', 'placeholder' => 'First Name'],
                'label' => 'First Name',
            ])
            ->add('lastName', TextType::class, [
                'attr' => ['class' => 'form-input', 'placeholder' => 'Last Name'],
                'label' => 'Last Name',
            ])
            ->add('email', EmailType::class, [
                'attr' => ['class' => 'form-input', 'placeholder' => 'Email Address'],
                'label' => 'Email',
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'attr' => ['class' => 'form-input', 'placeholder' => 'Password'],
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
                    'attr' => ['class' => 'form-input', 'placeholder' => 'Repeat Password'],
                    'label' => 'Repeat Password',
                ],
                'invalid_message' => 'The password fields must match.',
            ])
            ->add('dataPrivacyConsent', CheckboxType::class, [
                'mapped' => false,
                'label' => 'I have read and agree to the Data Privacy Act compliance statement.',
                'constraints' => [
                    new IsTrue(message: 'You must agree to the Data Privacy Act compliance statement.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
