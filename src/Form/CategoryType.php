<?php

namespace App\Form;

use App\Entity\Category;
use App\Enum\Icon;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('icon', EnumType::class, [
                'label' => 'Icône',
                'class' => Icon::class,
                'required' => false,
                'placeholder' => 'Aucune',
                'choice_label' => static fn (Icon $i): string => $i->label(),
            ])
            ->add('color', TextType::class, [
                'label' => 'Couleur',
                'required' => false,
                'help' => 'Format hexadécimal, ex. #3366ff (utilisée dans les graphiques).',
                'attr' => ['placeholder' => '#RRGGBB', 'maxlength' => 7],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
        ]);
    }
}
