<?php

namespace App\Form;

use App\Entity\Budget;
use App\Entity\Category;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CurrencyType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BudgetType extends AbstractType
{
    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', EntityType::class, [
                'label' => 'Catégorie',
                'class' => Category::class,
                // Ne propose que les catégories de l'utilisateur connecté.
                'query_builder' => fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('c')
                    ->where('c.owner = :owner')
                    ->setParameter('owner', $this->security->getUser())
                    ->orderBy('c.name', 'ASC'),
                'choice_label' => 'name',
            ])
            ->add('month', DateType::class, [
                'label' => 'Mois',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'N\'importe quel jour du mois : il est ramené au premier jour.',
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Plafond',
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01'],
            ])
            ->add('currency', CurrencyType::class, ['label' => 'Devise'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Budget::class,
        ]);
    }
}
