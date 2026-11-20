<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Formulaire d'un versement ou d'un retrait (pas d'entité : le service GoalSavings crée la ligne d'historique). */
class GoalContributionType extends AbstractType
{
    public const DEPOSIT = 'deposit';
    public const WITHDRAWAL = 'withdrawal';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('kind', ChoiceType::class, [
                'label' => 'Opération',
                'expanded' => true,
                'choices' => ['Versement' => self::DEPOSIT, 'Retrait' => self::WITHDRAWAL],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Montant',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01'],
                'constraints' => [new Assert\NotBlank(), new Assert\Positive()],
            ])
            ->add('date', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Note',
                'required' => false,
                'attr' => ['rows' => 2],
                'constraints' => [new Assert\Length(max: 1000)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
