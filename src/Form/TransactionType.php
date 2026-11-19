<?php

namespace App\Form;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\RecurrenceFrequency;
use App\Enum\TransactionType as Type;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TransactionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class)
            ->add('amount', NumberType::class, [
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01'],
            ])
            ->add('type', EnumType::class, [
                'class' => Type::class,
                'choice_label' => static fn (Type $t): string => match ($t) {
                    Type::Income => 'Revenu',
                    Type::Expense => 'Dépense',
                },
            ])
            ->add('date', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('note', TextareaType::class, ['required' => false])
            ->add('account', EntityType::class, [
                'class' => Account::class,
                'choice_label' => 'name',
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choice_label' => 'name',
            ])
            ->add('recurrence', EnumType::class, [
                'class' => RecurrenceFrequency::class,
                'required' => false,
                'placeholder' => 'Ponctuelle',
                'choice_label' => static fn (RecurrenceFrequency $f): string => match ($f) {
                    RecurrenceFrequency::Daily => 'Quotidienne',
                    RecurrenceFrequency::Weekly => 'Hebdomadaire',
                    RecurrenceFrequency::Monthly => 'Mensuelle',
                    RecurrenceFrequency::Yearly => 'Annuelle',
                },
            ])
            ->add('recurrenceEndDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
        ]);
    }
}
