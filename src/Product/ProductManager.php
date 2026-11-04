<?php

declare(strict_types=1);

namespace App\Product;

use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\ProductTranslation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class ProductManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/public')]
        private string $publicDirectory,
    ) {
    }

    public function prepareTranslations(Product $product): void
    {
        foreach (ProductTranslation::LOCALES as $locale) {
            if (null === $product->translation($locale)) {
                $product->addTranslation(new ProductTranslation($product, $locale));
            }
        }
    }

    /** @param list<UploadedFile> $files @param list<ProductImage> $removedImages */
    public function save(Product $product, array $pricing, array $files, string $kind, array $removedImages): void
    {
        $rate = ProductPricing::parseTaxRate($pricing['taxRate']);
        $net = 'ttc' === $pricing['priceBasis']
            ? ProductPricing::excludingTax($pricing['unitPriceInclTax'], $rate)
            : ProductPricing::normalize($pricing['unitPriceExclTax']);
        if ($product->isPublished() && $product->getImages()->count() - count($removedImages) + count($files) < 1) {
            throw new \InvalidArgumentException('Ajoutez au moins une image avant de publier le produit.');
        }
        $product->setUnitPriceExclTax($net)->setTaxRateBasisPoints($rate);
        $stored = [];
        try {
            foreach ($files as $file) {
                $path = $this->storeImage($file);
                $stored[] = $path;
                $position = 1 + array_reduce($product->getImages()->toArray(), static fn (int $max, ProductImage $image): int => max($max, $image->getPosition()), -1);
                if ('carton' === $kind) {
                    foreach ($product->getImages() as $image) $image->setPosition($image->getPosition() + 1);
                    $position = 0;
                }
                $product->addImage(new ProductImage($product, $path, $kind, $position));
            }
            foreach ($removedImages as $image) $product->removeImage($image);
            $product->touch();
            $this->entityManager->persist($product);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            foreach ($stored as $path) {
                $file = $this->publicDirectory.'/'.$path;
                if (is_file($file)) unlink($file);
            }
            throw $exception;
        }
    }

    private function storeImage(UploadedFile $file): string
    {
        if (!$file->isValid() || !in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true) || ($file->getSize() ?? 0) > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('Utilisez une image JPG, PNG ou WebP de 8 Mo au maximum.');
        }
        $source = imagecreatefromstring(file_get_contents($file->getPathname()));
        if (false === $source) throw new \InvalidArgumentException('Cette image ne peut pas être décodée.');
        $scale = min(1, 1800 / imagesx($source), 1800 / imagesy($source));
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));
        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        $directory = $this->publicDirectory.'/uploads/products';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Le dossier des images produit ne peut pas être créé.');
        }
        $name = bin2hex(random_bytes(16)).'.webp';
        $saved = imagewebp($target, $directory.'/'.$name, 90);
        imagedestroy($source);
        imagedestroy($target);
        if (!$saved) throw new \RuntimeException('L’image ne peut pas être enregistrée.');

        return 'uploads/products/'.$name;
    }
}
