<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ProductImage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductImageType extends AbstractType
{
    public const KINDS = ['Carton' => 'carton', 'Produit unitaire' => 'unit', 'Détail' => 'detail'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('kind', ChoiceType::class, ['label' => 'Type', 'choices' => self::KINDS])
            ->add('position', IntegerType::class, ['label' => 'Ordre', 'attr' => ['min' => 0, 'max' => 10000]])
            ->add('remove', CheckboxType::class, ['label' => 'Retirer cette image', 'mapped' => false, 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProductImage::class]);
    }
}
