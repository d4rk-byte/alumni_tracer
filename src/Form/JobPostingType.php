<?php

namespace App\Form;

use App\Entity\JobPosting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class JobPostingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Job Title',
                'attr' => ['placeholder' => 'e.g. Software Engineer', 'class' => 'form-input'],
            ])
            ->add('companyName', TextType::class, [
                'label' => 'Company Name',
                'attr' => ['placeholder' => 'e.g. Accenture Philippines', 'class' => 'form-input'],
            ])
            ->add('location', TextType::class, [
                'label' => 'Location',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Dumaguete City, Negros Oriental', 'class' => 'form-input'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Job Description',
                'attr' => ['rows' => 5, 'class' => 'form-input', 'placeholder' => 'Describe the role, responsibilities, and benefits...'],
            ])
            ->add('requirements', TextareaType::class, [
                'label' => 'Qualifications / Requirements',
                'required' => false,
                'attr' => ['rows' => 4, 'class' => 'form-input', 'placeholder' => 'List requirements, qualifications, and preferred skills...'],
            ])
            ->add('salaryRange', TextType::class, [
                'label' => 'Salary Range',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. ₱20,000 - ₱35,000', 'class' => 'form-input'],
            ])
            ->add('employmentType', ChoiceType::class, [
                'label' => 'Employment Type',
                'required' => false,
                'placeholder' => '-- Select --',
                'choices' => [
                    'Full-time' => 'Full-time',
                    'Part-time' => 'Part-time',
                    'Contract' => 'Contract',
                    'Freelance' => 'Freelance',
                    'Internship' => 'Internship',
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('industry', TextType::class, [
                'label' => 'Industry',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Information Technology', 'class' => 'form-input'],
            ])
            ->add('relatedCourse', TextType::class, [
                'label' => 'Related Course / Program',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. BS Information Technology', 'class' => 'form-input'],
                'help' => 'Target course for job-to-degree alignment reporting.',
            ])
            ->add('contactEmail', EmailType::class, [
                'label' => 'Contact Email',
                'required' => false,
                'attr' => ['placeholder' => 'hr@company.com', 'class' => 'form-input'],
            ])
            ->add('applicationLink', UrlType::class, [
                'label' => 'Application Link',
                'required' => false,
                'attr' => ['placeholder' => 'https://...', 'class' => 'form-input'],
            ])
            ->add('imageFile', FileType::class, [
                'label' => 'Job Image',
                'mapped' => false,
                'required' => false,
                'help' => 'Optional. This image is shown in the Job Opportunities landing cards.',
                'attr' => [
                    'accept' => '.jpg,.jpeg,.png,.webp',
                    'class' => 'form-input',
                ],
                'constraints' => [
                    new Assert\File([
                        'maxSize' => '3M',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                        'mimeTypesMessage' => 'Please upload a valid image (JPG, PNG, or WEBP).',
                    ]),
                ],
            ])
            ->add('deadline', DateType::class, [
                'label' => 'Application Deadline',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'form-input'],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Active (visible to alumni)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => JobPosting::class,
        ]);
    }
}
