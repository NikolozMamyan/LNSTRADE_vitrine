<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\ProductImage;
use App\Repository\ProductRepository;

final readonly class ShopCatalog
{
    public function __construct(
        private ProductRepository $products,
    ) {
    }

    public function products(string $locale = 'fr'): array
    {
        $catalog = [];
        foreach ($this->products->published() as $product) {
            $translation = $product->translation($locale);
            $localized = [];
            foreach (['name', 'character', 'flavor', 'format', 'family', 'description', 'ingredients'] as $field) {
                $getter = 'get'.ucfirst($field);
                $value = $translation?->$getter() ?? '';
                $localized[$field] = '' !== $value ? $value : $product->$getter();
            }
            $images = $product->getImages()->toArray();
            $unit = $product->getImages()->findFirst(static fn (int $key, ProductImage $image): bool => 'unit' === $image->getKind());
            $catalog[] = $localized + $product->getPricing() + [
                'reference' => $product->getReference(), 'group' => $product->getCategoryGroup(),
                'license' => $product->getLicense(), 'ean' => $product->getEan(), 'pcb' => $product->getPcb(),
                'variantGroup' => json_encode([$product->getCategoryGroup(), $product->getFamily(), $product->getFormat()], JSON_THROW_ON_ERROR),
                'available' => $product->isPurchasable(), 'bestseller' => $product->isBestseller(),
                'unitPriceExclTax' => $product->getUnitPriceExclTax(), 'taxRateBasisPoints' => $product->getTaxRateBasisPoints(),
                'images' => array_map(static fn (ProductImage $image): string => $image->getPath(), $images),
                'variantImage' => $unit?->getPath() ?? ($images[0]->getPath() ?? ''),
                'ingredientsLocale' => '' !== ($translation?->getIngredients() ?? '') ? $locale : 'fr',
            ];
        }

        return $catalog;
    }
}
