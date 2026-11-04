<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ProductTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductTranslationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['name' => 'Nom', 'character' => 'Personnage', 'flavor' => 'Saveur', 'format' => 'Format', 'family' => 'Gamme', 'description' => 'Description', 'ingredients' => 'Ingrédients'] as $field => $label) {
            $builder->add($field, in_array($field, ['description', 'ingredients'], true) ? TextareaType::class : TextType::class, [
                'label' => $label, 'required' => false, 'empty_data' => '',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProductTranslation::class]);
    }
}
