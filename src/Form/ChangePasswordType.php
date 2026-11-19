<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'Nouveau mot de passe',
                'attr' => ['autocomplete' => 'new-password', 'autofocus' => true],
                'help' => '10 caractères minimum.',
            ],
            'second_options' => [
                'label' => 'Confirmer le mot de passe',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
            'constraints' => [
                new NotBlank(message: 'Choisissez un mot de passe.'),
                new Length(min: 10, max: 4096, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.'),
            ],
        ]);
    }
}
