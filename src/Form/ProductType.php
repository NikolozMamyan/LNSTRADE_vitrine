<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Product;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Product $product */
        $product = $options['data'];
        foreach (['reference' => 'Référence', 'name' => 'Nom du produit', 'character' => 'Personnage', 'flavor' => 'Saveur', 'format' => 'Format', 'family' => 'Gamme', 'license' => 'Licence'] as $field => $label) {
            $builder->add($field, TextType::class, ['label' => $label, 'empty_data' => '']);
        }
        foreach (['description' => 'Description', 'ingredients' => 'Ingrédients'] as $field => $label) {
            $builder->add($field, TextareaType::class, ['label' => $label, 'required' => false, 'empty_data' => '', 'attr' => ['rows' => 4]]);
        }
        $builder->add('ean', TextType::class, ['label' => 'EAN', 'required' => false])
            ->add('categoryGroup', ChoiceType::class, ['label' => 'Catégorie', 'choices' => ['Boissons' => 'drinks', 'Snacks salés' => 'snacks', 'Douceurs' => 'sweet']])
            ->add('pcb', IntegerType::class, ['label' => 'PCB · unités par carton', 'attr' => ['min' => 1, 'max' => 10000, 'data-product-pricing-target' => 'pcb', 'data-action' => 'input->product-pricing#calculate']])
            ->add('stockCartons', IntegerType::class, ['label' => 'Stock disponible · cartons', 'required' => false, 'help' => 'Laissez vide pour ne pas suivre le stock. Zéro rend le produit indisponible.', 'attr' => ['min' => 0]])
            ->add('sortOrder', IntegerType::class, ['label' => 'Ordre dans la boutique', 'attr' => ['min' => 0]])
            ->add('published', CheckboxType::class, ['label' => 'Publié dans la boutique', 'required' => false])
            ->add('available', CheckboxType::class, ['label' => 'Disponible à la commande', 'required' => false])
            ->add('bestseller', CheckboxType::class, ['label' => 'Mettre en avant · best-seller', 'required' => false]);
        foreach (['unitPriceExclTax' => ['Prix unitaire HT · €', $product->getUnitPriceExclTax(), 'net', 'netInput'], 'unitPriceInclTax' => ['Prix unitaire TTC · €', $product->getUnitPriceInclTax(), 'gross', 'grossInput']] as $field => [$label, $value, $target, $action]) {
            $builder->add($field, TextType::class, ['label' => $label, 'mapped' => false, 'data' => $value, 'constraints' => [new Assert\NotBlank()], 'attr' => ['inputmode' => 'decimal', 'data-product-pricing-target' => $target, 'data-action' => 'input->product-pricing#'.$action]]);
        }
        $builder->add('taxRate', TextType::class, ['label' => 'TVA · %', 'mapped' => false, 'data' => number_format($product->getTaxRateBasisPoints() / 100, 2, '.', ''), 'constraints' => [new Assert\NotBlank()], 'attr' => ['inputmode' => 'decimal', 'data-product-pricing-target' => 'rate', 'data-action' => 'input->product-pricing#calculate']])
            ->add('priceBasis', HiddenType::class, ['mapped' => false, 'data' => 'ht', 'constraints' => [new Assert\Choice(choices: ['ht', 'ttc'])], 'attr' => ['data-product-pricing-target' => 'basis']])
            ->add('translations', CollectionType::class, ['entry_type' => ProductTranslationType::class])
            ->add('images', CollectionType::class, ['entry_type' => ProductImageType::class])
            ->add('newImageKind', ChoiceType::class, ['label' => 'Type des nouvelles images', 'mapped' => false, 'choices' => ProductImageType::KINDS, 'data' => 'unit'])
            ->add('newImages', FileType::class, ['label' => 'Ajouter des images', 'mapped' => false, 'multiple' => true, 'required' => false, 'attr' => ['accept' => 'image/jpeg,image/png,image/webp'], 'constraints' => [new Assert\Count(max: 12), new Assert\All([new Assert\Image(maxSize: '8M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 10000, maxHeight: 10000)])]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Product::class, 'csrf_token_id' => 'product_edit', 'translation_domain' => false]);
    }
}
