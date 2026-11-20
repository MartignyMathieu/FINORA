<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/** Restauration d'une sauvegarde : fichier, mot de passe actuel et confirmation explicite (les données actuelles sont remplacées). */
class BackupRestoreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Fichier de sauvegarde',
                'help' => 'Un fichier .json téléchargé depuis FINORA (20 Mo au plus).',
                'attr' => ['accept' => '.json,application/json'],
                'constraints' => [
                    new Assert\NotNull(message: 'Choisissez un fichier de sauvegarde.'),
                    new Assert\File(
                        maxSize: '20M',
                        extensions: [
                            'json' => ['application/json', 'text/plain', 'text/json', 'application/octet-stream'],
                            'txt' => ['text/plain', 'application/json'],
                        ],
                        maxSizeMessage: 'Le fichier dépasse 20 Mo.',
                        extensionsMessage: 'Le fichier doit être une sauvegarde au format .json.',
                    ),
                ],
            ])
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'attr' => ['autocomplete' => 'current-password'],
                'help' => 'Pour confirmer le remplacement de vos données.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Saisissez votre mot de passe actuel.'),
                    new UserPassword(message: 'Mot de passe actuel incorrect.'),
                ],
            ])
            ->add('confirm', CheckboxType::class, [
                'label' => 'Je comprends que toutes mes données actuelles (comptes, transactions, budgets, objectifs…) seront remplacées par celles de la sauvegarde.',
                'required' => false,
                'constraints' => [new Assert\IsTrue(message: 'Cochez la case pour confirmer le remplacement.')],
            ]);
    }

    public function getBlockPrefix(): string
    {
        return 'backup_restore';
    }
}
