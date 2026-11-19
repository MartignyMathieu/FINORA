<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Suppression définitive du compte, soumise au mot de passe actuel. */
class DeleteAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('currentPassword', PasswordType::class, [
            'label' => 'Mot de passe actuel',
            'attr' => ['autocomplete' => 'current-password'],
            'help' => 'Pour confirmer que vous souhaitez tout supprimer.',
            'constraints' => [
                new NotBlank(message: 'Saisissez votre mot de passe actuel.'),
                new UserPassword(message: 'Mot de passe actuel incorrect.'),
            ],
        ]);
    }
}
