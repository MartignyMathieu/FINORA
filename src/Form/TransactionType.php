<?php

namespace App\Form;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\TransactionType as Type;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
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
    public function __construct(private readonly Security $security)
    {
    }

    /** Ne propose que les comptes et catégories de l'utilisateur connecté. */
    private function ownedByCurrentUser(): \Closure
    {
        return fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('e')
            ->where('e.owner = :owner')
            ->setParameter('owner', $this->security->getUser())
            ->orderBy('e.name', 'ASC');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
            ->add('account', EntityType::class, [
                'label' => 'Compte',
                'class' => Account::class,
                'query_builder' => $this->ownedByCurrentUser(),
                'choice_label' => 'name',
            ])
            ->add('category', EntityType::class, [
                'label' => 'Catégorie',
                'class' => Category::class,
                'query_builder' => $this->ownedByCurrentUser(),
                'choice_label' => 'name',
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
