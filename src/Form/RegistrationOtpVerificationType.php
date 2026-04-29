<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationOtpVerificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('otpCode', TextType::class, [
            'mapped' => false,
            'label' => 'Verification Code',
            'attr' => [
                'class' => 'form-input',
                'autocomplete' => 'one-time-code',
                'inputmode' => 'numeric',
                'maxlength' => 6,
                'placeholder' => 'Enter the 6-digit code',
            ],
            'constraints' => [
                new NotBlank(message: 'Please enter the verification code.'),
                new Length(min: 6, max: 6, exactMessage: 'The verification code must be exactly {{ limit }} digits.'),
                new Regex(pattern: '/^\d{6}$/', message: 'The verification code must contain 6 digits.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}