<?php

namespace App\Form;

use App\Entity\User;
use App\Enum\Avatar;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Prénom affiché et pictogramme de profil. */
class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Prénom',
                'attr' => ['autocomplete' => 'given-name'],
            ])
            ->add('avatar', EnumType::class, [
                'class' => Avatar::class,
                'label' => 'Icône de profil',
                'required' => false,
                'expanded' => true,
                'placeholder' => 'Initiale',
                'choice_label' => static fn (Avatar $avatar): string => $avatar->label(),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
