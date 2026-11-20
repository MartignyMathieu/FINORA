<?php

namespace App\Form;

use App\Entity\Account;
use App\Entity\Transfer;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TransferType extends AbstractType
{
    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Ne propose que les comptes de l'utilisateur connecté.
        $accounts = fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('a')
            ->where('a.owner = :owner')
            ->setParameter('owner', $this->security->getUser())
            ->orderBy('a.name', 'ASC');
        $label = static fn (Account $account): string => sprintf('%s (%s)', $account->getName(), $account->getCurrency());

        $builder
            ->add('fromAccount', EntityType::class, [
                'label' => 'Depuis le compte',
                'class' => Account::class,
                'query_builder' => $accounts,
                'choice_label' => $label,
                'placeholder' => 'Choisir un compte',
            ])
            ->add('toAccount', EntityType::class, [
                'label' => 'Vers le compte',
                'class' => Account::class,
                'query_builder' => $accounts,
                'choice_label' => $label,
                'placeholder' => 'Choisir un compte',
                'help' => 'Même devise que le compte débité : aucune conversion n\'est faite.',
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Montant',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01'],
            ])
            ->add('date', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Note',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Transfer::class]);
    }
}
