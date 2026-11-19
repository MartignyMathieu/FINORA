<?php

namespace App\Form;

use App\Entity\Account;
use App\Enum\AccountType as Kind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CurrencyType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('type', EnumType::class, [
                'label' => 'Type de compte',
                'class' => Kind::class,
                'choice_label' => static fn (Kind $k): string => $k->label(),
            ])
            ->add('currency', CurrencyType::class, ['label' => 'Devise'])
            ->add('initialBalance', NumberType::class, [
                'label' => 'Solde initial',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Account::class,
        ]);
    }
}
