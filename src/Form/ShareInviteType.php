<?php

namespace App\Form;

use App\Enum\ShareRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** Invitation à partager un compte : adresse e-mail d'une personne qui a déjà un compte FINORA, et niveau de droits. */
class ShareInviteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail de la personne',
                'attr' => ['autocomplete' => 'off', 'placeholder' => 'prenom@exemple.fr'],
                'help' => 'Elle doit déjà avoir un compte FINORA. L\'invitation apparaît dans son application (Comptes → Comptes partagés) : aucun e-mail n\'est envoyé.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Saisissez une adresse e-mail.'),
                    new Assert\Email(message: 'Adresse e-mail invalide.'),
                    new Assert\Length(max: 180),
                ],
            ])
            ->add('role', EnumType::class, [
                'label' => 'Droits',
                'class' => ShareRole::class,
                'expanded' => true,
                'choice_label' => static fn (ShareRole $role): string => $role->label().' — '.$role->description(),
            ]);
    }

    public function getBlockPrefix(): string
    {
        return 'share_invite';
    }
}
