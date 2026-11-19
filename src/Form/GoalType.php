<?php

namespace App\Form;

use App\Entity\Goal;
use App\Enum\Icon;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CurrencyType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GoalType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $money = ['input' => 'string', 'scale' => 2, 'html5' => true, 'attr' => ['step' => '0.01', 'min' => '0']];

        $builder
            ->add('name', TextType::class, ['label' => 'Nom de l\'objectif'])
            ->add('icon', EnumType::class, [
                'label' => 'Icône',
                'class' => Icon::class,
                'required' => false,
                'placeholder' => 'Objectif',
                'choice_label' => static fn (Icon $i): string => $i->label(),
            ])
            ->add('targetAmount', NumberType::class, ['label' => 'Montant à atteindre'] + $money)
            ->add('savedAmount', NumberType::class, ['label' => 'Déjà épargné'] + $money)
            ->add('currency', CurrencyType::class, ['label' => 'Devise'])
            ->add('targetDate', DateType::class, [
                'label' => 'Échéance',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Goal::class,
        ]);
    }
}
