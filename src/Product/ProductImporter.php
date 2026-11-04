<?php

declare(strict_types=1);

namespace App\Product;

use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductTranslation;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ProductImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProductRepository $products,
        private TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%/assets/data/ultrapop-products.json')]
        private string $snapshotPath,
    ) {
    }

    public function import(bool $imagesOnly = false): array
    {
        $snapshot = json_decode(file_get_contents($this->snapshotPath), true, flags: JSON_THROW_ON_ERROR);
        $counts = ['created' => 0, 'skipped' => 0, 'images' => 0];
        foreach ($snapshot['products'] as $position => $row) {
            $product = $this->products->findOneBy(['reference' => $row['reference']]);
            if (null !== $product && !$imagesOnly) { ++$counts['skipped']; continue; }
            if (null === $product && $imagesOnly) { ++$counts['skipped']; continue; }
            if (null === $product) {
                $product = (new Product())->setReference($row['reference'])->setName($row['name'])
                    ->setCharacter($row['character'])->setFlavor($row['flavor'])->setFormat($row['format'])
                    ->setFamily($row['family'])->setLicense($row['license'])->setCategoryGroup($row['group'])
                    ->setDescription($row['description'])->setIngredients($row['ingredients'])->setEan($row['ean'])
                    ->setUnitPriceExclTax($row['unitPriceExclTax'])->setTaxRateBasisPoints($row['taxRateBasisPoints'])
                    ->setPcb($row['pcb'])->setAvailable($row['available'])->setBestseller($row['bestseller'])
                    ->setPublished(true)->setSortOrder($position);
                foreach (ProductTranslation::LOCALES as $locale) {
                    $flavorKey = 'flavor.'.$row['reference'];
                    $flavor = $this->translator->trans($flavorKey, [], 'shop', $locale);
                    $familyKey = 'family.'.$row['family'];
                    $family = $this->translator->trans($familyKey, [], 'shop', $locale);
                    $family = $familyKey === $family ? $row['family'] : $family;
                    $product->addTranslation((new ProductTranslation($product, $locale))
                        ->setFlavor($flavorKey === $flavor ? $row['flavor'] : $flavor)->setFamily($family)
                        ->setDescription($this->translator->trans('productIntro', ['%family%' => $family, '%license%' => $row['license'], '%character%' => $row['character']], 'shop', $locale)));
                }
                $this->entityManager->persist($product);
                ++$counts['created'];
            }
            $paths = array_map(static fn (ProductImage $image): string => $image->getPath(), $product->getImages()->toArray());
            foreach ($row['images'] as $index => $path) {
                if (in_array($path, $paths, true)) continue;
                $kind = str_contains($path, '/cartons/') ? 'carton' : (str_contains($path, '-detail.') ? 'detail' : 'unit');
                if ($imagesOnly && 'carton' === $kind) {
                    foreach ($product->getImages() as $image) $image->setPosition($image->getPosition() + 1);
                }
                $product->addImage(new ProductImage($product, $path, $kind, $index));
                ++$counts['images'];
            }
            $product->touch();
        }
        $this->entityManager->flush();

        return $counts;
    }
}
