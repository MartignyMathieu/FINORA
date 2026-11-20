<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\TransactionType as Type;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie d'une transaction sur un compte partagé : le compte est imposé, et les catégories proposées sont celles du propriétaire
 * du compte (les données d'un compte restent cohérentes pour les deux personnes).
 */
class SharedTransactionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $owner = $options['account_owner'];

        $builder
            ->add('label', TextType::class, ['label' => 'Libellé'])
            ->add('amount', NumberType::class, [
                'label' => 'Montant',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01'],
            ])
            ->add('type', EnumType::class, [
                'label' => 'Type',
                'class' => Type::class,
                'choice_label' => static fn (Type $t): string => $t->label(),
            ])
            ->add('date', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('note', TextareaType::class, ['label' => 'Note', 'required' => false])
            ->add('category', EntityType::class, [
                'label' => 'Catégorie',
                'class' => Category::class,
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('c')
                    ->where('c.owner = :owner')->setParameter('owner', $owner)->orderBy('c.name', 'ASC'),
                'choice_label' => 'name',
                'help' => 'Les catégories sont celles du propriétaire du compte.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('account_owner');
        $resolver->setAllowedTypes('account_owner', User::class);
        $resolver->setDefaults(['data_class' => Transaction::class]);
    }
}
