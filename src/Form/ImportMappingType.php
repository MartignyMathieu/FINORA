<?php

namespace App\Form;

use App\Entity\Account;
use App\Entity\Category;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Correspondance entre les colonnes du fichier et les champs d'une transaction, et options de l'import.
 * Les colonnes sont désignées par leur position (0, 1, 2…) dans la ligne d'en-têtes.
 */
class ImportMappingType extends AbstractType
{
    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $columns = [];
        foreach ($options['header'] as $index => $name) {
            $columns[sprintf('Colonne %d : %s', $index + 1, '' === $name ? '(sans nom)' : $name)] = (string) $index;
        }

        $required = ['placeholder' => 'Choisir la colonne', 'constraints' => [new Assert\NotBlank(message: 'Indiquez la colonne correspondante.')]];
        $optional = ['placeholder' => 'Aucune', 'required' => false];

        $builder
            ->add('date', ChoiceType::class, ['label' => 'Date', 'choices' => $columns] + $required)
            ->add('label', ChoiceType::class, ['label' => 'Libellé', 'choices' => $columns] + $required)
            ->add('amount', ChoiceType::class, ['label' => 'Montant', 'choices' => $columns, 'help' => 'Négatif = dépense, positif = revenu (sauf si une colonne « Type » est indiquée).'] + $required)
            ->add('type', ChoiceType::class, ['label' => 'Type (revenu ou dépense)', 'choices' => $columns, 'help' => 'Facultatif : si elle est indiquée, le montant est pris sans son signe.'] + $optional)
            ->add('account', ChoiceType::class, ['label' => 'Compte', 'choices' => $columns, 'help' => 'Facultatif : nom d\'un de vos comptes. Sans colonne, tout va dans le compte choisi ci-dessous.'] + $optional)
            ->add('category', ChoiceType::class, ['label' => 'Catégorie', 'choices' => $columns, 'help' => 'Facultatif : nom de catégorie.'] + $optional)
            ->add('note', ChoiceType::class, ['label' => 'Note', 'choices' => $columns] + $optional)
            ->add('defaultAccount', EntityType::class, [
                'label' => 'Compte de destination',
                'class' => Account::class,
                'query_builder' => fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('a')
                    ->where('a.owner = :owner')->setParameter('owner', $this->security->getUser())->orderBy('a.name', 'ASC'),
                'choice_label' => static fn (Account $account): string => sprintf('%s (%s)', $account->getName(), $account->getCurrency()),
                'placeholder' => 'Choisir un compte',
                'constraints' => [new Assert\NotNull(message: 'Choisissez un compte.')],
            ])
            ->add('fallbackCategory', EntityType::class, [
                'label' => 'Catégorie par défaut',
                'class' => Category::class,
                'query_builder' => fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('c')
                    ->where('c.owner = :owner')->setParameter('owner', $this->security->getUser())->orderBy('c.name', 'ASC'),
                'choice_label' => 'name',
                'placeholder' => 'Choisir une catégorie',
                'help' => 'Pour les lignes sans catégorie.',
                'constraints' => [new Assert\NotNull(message: 'Choisissez une catégorie par défaut.')],
            ])
            ->add('createCategories', CheckboxType::class, ['label' => 'Créer les catégories qui n\'existent pas encore', 'required' => false])
            ->add('skipDuplicates', CheckboxType::class, ['label' => 'Ignorer les transactions déjà présentes (même compte, date, montant et libellé)', 'required' => false])
            ->add('preview', SubmitType::class, ['label' => 'Voir l\'aperçu'])
            ->add('import', SubmitType::class, ['label' => 'Importer']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('header');
        $resolver->setAllowedTypes('header', 'array');
        $resolver->setDefaults(['data_class' => null]);
    }
}
