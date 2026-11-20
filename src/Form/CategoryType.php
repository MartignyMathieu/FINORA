<?php

namespace App\Form;

use App\Entity\Category;
use App\Enum\CategoryColors;
use App\Enum\Icon;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
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
        ;

        // Une catégorie créée avant les palettes peut avoir une couleur hors palette : elle reste proposée (et conservée)
        // sous « Couleur actuelle » tant qu'on n'en choisit pas une autre.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $current = $event->getData()?->getColor();
            $this->addColor($event->getForm(), null !== $current && null === CategoryColors::paletteOf($current) ? $current : null);
        });
    }

    private function addColor(FormInterface $form, ?string $legacy): void
    {
        $choices = [];
        $palettes = [];
        foreach (CategoryColors::PALETTES as $palette => $colors) {
            foreach ($colors as $name => $hex) {
                $choices[$name] = $hex;
                $palettes[$hex] = $palette;
            }
        }
        if (null !== $legacy) {
            $choices['Couleur actuelle'] = $legacy;
            $palettes[$legacy] = 'Couleur actuelle';
        }

        $form->add('color', ChoiceType::class, [
            'label' => 'Couleur',
            'required' => false,
            'expanded' => true,
            'placeholder' => 'Automatique',
            'choices' => $choices,
            'choice_attr' => static fn (string $hex): array => ['data-palette' => $palettes[$hex] ?? ''],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
        ]);
    }
}
