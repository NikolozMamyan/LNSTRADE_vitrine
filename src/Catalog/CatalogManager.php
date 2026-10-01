<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\Catalog;
use App\Entity\CatalogPage;
use App\Repository\CatalogPageRepository;
use App\Repository\CatalogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class CatalogManager
{
    private const MAX_IMAGE_SIZE = 15 * 1024 * 1024;
    private const MAX_PDF_SIZE = 90 * 1024 * 1024;
    private const MAX_PDF_PAGES = 200;
    private const UPLOAD_PREFIX = '/uploads/catalogue/';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CatalogRepository $catalogs,
        private CatalogPageRepository $pages,
        #[Autowire('%kernel.project_dir%/public')]
        private string $publicDirectory,
    ) {
    }

    public function current(): Catalog
    {
        return $this->catalogs->findCurrent() ?? throw new \LogicException('Le catalogue n’est pas initialisé. Exécutez les migrations Doctrine.');
    }

    public function saveSettings(Catalog $catalog, string $title, bool $enabled): void
    {
        $title = trim($title);
        if ('' === $title || mb_strlen($title) > 180) {
            throw new \InvalidArgumentException('Le titre doit contenir entre 1 et 180 caractères.');
        }
        if ($enabled && [] === $this->pages->findForCatalog($catalog, true)) {
            throw new \InvalidArgumentException('Ajoutez au moins une page visible avant de publier le catalogue.');
        }

        $catalog->setTitle($title)->setEnabled($enabled);
        $catalog->touch();
        $this->entityManager->flush();
    }

    /** @param list<UploadedFile> $files */
    public function addImages(Catalog $catalog, array $files): int
    {
        $files = array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
        if ([] === $files) {
            throw new \InvalidArgumentException('Sélectionnez au moins une image.');
        }
        if (count($files) > 50) {
            throw new \InvalidArgumentException('Vous pouvez ajouter au maximum 50 images à la fois.');
        }

        $position = $this->pages->nextPosition($catalog);
        foreach ($files as $file) {
            $title = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $page = (new CatalogPage($catalog, $position++))
                ->setTitle(mb_substr('' !== trim($title) ? $title : 'Nouvelle page', 0, 180))
                ->setImagePath($this->storeImage($file));
            $this->entityManager->persist($page);
        }

        $catalog->touch();
        $this->entityManager->flush();

        return count($files);
    }

    public function addPdf(Catalog $catalog, UploadedFile $file, int $requestedPageCount, string $requestedTitle): int
    {
        $pageCount = $requestedPageCount > 0 ? $requestedPageCount : $this->detectPdfPageCount($file);
        if ($pageCount < 1 || $pageCount > self::MAX_PDF_PAGES) {
            throw new \InvalidArgumentException(sprintf('Le PDF doit contenir entre 1 et %d pages.', self::MAX_PDF_PAGES));
        }

        $pdfPath = $this->storePdf($file);
        $fallbackTitle = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $baseTitle = trim($requestedTitle);
        $baseTitle = mb_substr('' !== $baseTitle ? $baseTitle : ('' !== trim($fallbackTitle) ? $fallbackTitle : 'Catalogue ULTRAPOP'), 0, 150);
        $position = $this->pages->nextPosition($catalog);

        for ($number = 1; $number <= $pageCount; ++$number) {
            $title = 1 === $pageCount ? $baseTitle : sprintf('%s · page %d', $baseTitle, $number);
            $page = (new CatalogPage($catalog, $position++))
                ->setTitle(mb_substr($title, 0, 180))
                ->setPdfPath($pdfPath)
                ->setPdfPage($number);
            $this->entityManager->persist($page);
        }

        $catalog->touch();
        $this->entityManager->flush();

        return $pageCount;
    }

    public function updatePage(
        CatalogPage $page,
        string $title,
        string $linkUrl,
        bool $openInNewTab,
        bool $enabled,
        ?UploadedFile $replacement,
    ): void {
        $title = trim($title);
        if ('' === $title || mb_strlen($title) > 180) {
            throw new \InvalidArgumentException('Le titre de la page doit contenir entre 1 et 180 caractères.');
        }

        $oldImage = $page->getImagePath();
        $oldPdf = $page->getPdfPath();
        if ($replacement instanceof UploadedFile) {
            if ($this->isPdf($replacement)) {
                $page->setImagePath(null)->setPdfPath($this->storePdf($replacement))->setPdfPage(1);
            } else {
                $page->setImagePath($this->storeImage($replacement))->setPdfPath(null)->setPdfPage(null);
            }
        }

        $page
            ->setTitle($title)
            ->setLinkUrl($this->normaliseLink($linkUrl))
            ->setOpenLinkInNewTab($openInNewTab)
            ->setEnabled($enabled);
        $page->touch();
        $page->getCatalog()->touch();
        $this->entityManager->flush();
        $this->deleteUnusedMedia($oldImage, $oldPdf);
    }

    public function move(CatalogPage $page, string $direction): void
    {
        $orderedPages = $this->pages->findForCatalog($page->getCatalog());
        $index = array_search($page, $orderedPages, true);
        if (false === $index) {
            return;
        }

        $targetIndex = 'up' === $direction ? $index - 1 : $index + 1;
        if (!isset($orderedPages[$targetIndex])) {
            return;
        }

        $target = $orderedPages[$targetIndex];
        $position = $page->getPosition();
        $page->setPosition($target->getPosition())->touch();
        $target->setPosition($position)->touch();
        $page->getCatalog()->touch();
        $this->entityManager->flush();
    }

    public function delete(CatalogPage $page): void
    {
        $catalog = $page->getCatalog();
        $oldImage = $page->getImagePath();
        $oldPdf = $page->getPdfPath();
        $this->entityManager->remove($page);
        $this->entityManager->flush();

        foreach ($this->pages->findForCatalog($catalog) as $index => $remainingPage) {
            $remainingPage->setPosition($index + 1);
        }
        $catalog->touch();
        $this->entityManager->flush();
        $this->deleteUnusedMedia($oldImage, $oldPdf);
    }

    private function storeImage(UploadedFile $file): string
    {
        if (!$file->isValid() || ($file->getSize() ?? 0) > self::MAX_IMAGE_SIZE) {
            throw new \InvalidArgumentException('L’image doit être valide et ne pas dépasser 15 Mo.');
        }

        $contents = file_get_contents($file->getPathname());
        $source = false === $contents ? false : imagecreatefromstring($contents);
        if (false === $source) {
            throw new \InvalidArgumentException('Utilisez une image JPG, PNG ou WebP valide.');
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(1, 2400 / $sourceWidth, 3400 / $sourceHeight);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        $filename = bin2hex(random_bytes(16)).'.webp';
        $saved = imagewebp($target, $this->uploadDirectory().'/'.$filename, 90);
        imagedestroy($source);
        imagedestroy($target);
        if (!$saved) {
            throw new \RuntimeException('L’image ne peut pas être enregistrée.');
        }

        return self::UPLOAD_PREFIX.$filename;
    }

    private function storePdf(UploadedFile $file): string
    {
        if (!$file->isValid() || ($file->getSize() ?? 0) > self::MAX_PDF_SIZE || !$this->isPdf($file)) {
            throw new \InvalidArgumentException('Le PDF doit être valide et ne pas dépasser 90 Mo.');
        }

        $filename = bin2hex(random_bytes(16)).'.pdf';
        $file->move($this->uploadDirectory(), $filename);

        return self::UPLOAD_PREFIX.$filename;
    }

    private function isPdf(UploadedFile $file): bool
    {
        $handle = fopen($file->getPathname(), 'rb');
        $signature = false === $handle ? '' : fread($handle, 5);
        if (is_resource($handle)) {
            fclose($handle);
        }

        return '%PDF-' === $signature;
    }

    private function detectPdfPageCount(UploadedFile $file): int
    {
        $contents = file_get_contents($file->getPathname());
        if (false === $contents) {
            return 0;
        }

        preg_match_all('/\/Type\s*\/Page\b/', $contents, $matches);

        return count($matches[0]);
    }

    private function normaliseLink(string $link): ?string
    {
        $link = trim(str_replace(["\r", "\n"], '', $link));
        if ('' === $link) {
            return null;
        }
        if (mb_strlen($link) > 2048) {
            throw new \InvalidArgumentException('Le lien de redirection est trop long.');
        }
        if (str_starts_with($link, '/') && !str_starts_with($link, '//')) {
            return $link;
        }
        if (preg_match('/^(mailto:[^\s@]+@[^\s@]+|tel:\+?[0-9 .()-]+)$/i', $link)) {
            return $link;
        }

        $scheme = parse_url($link, PHP_URL_SCHEME);
        if (!filter_var($link, FILTER_VALIDATE_URL) || !in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Utilisez une URL HTTP(S), un lien interne, une adresse e-mail ou un numéro de téléphone.');
        }

        return $link;
    }

    private function uploadDirectory(): string
    {
        $directory = $this->publicDirectory.self::UPLOAD_PREFIX;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Le dossier d’upload du catalogue ne peut pas être créé.');
        }

        return rtrim($directory, '/');
    }

    private function deleteUnusedMedia(?string $imagePath, ?string $pdfPath): void
    {
        if (null !== $imagePath && 0 === $this->pages->countImageReferences($imagePath)) {
            $this->deleteUpload($imagePath);
        }
        if (null !== $pdfPath && 0 === $this->pages->countPdfReferences($pdfPath)) {
            $this->deleteUpload($pdfPath);
        }
    }

    private function deleteUpload(string $path): void
    {
        if (!str_starts_with($path, self::UPLOAD_PREFIX)) {
            return;
        }

        $file = $this->publicDirectory.self::UPLOAD_PREFIX.basename($path);
        if (is_file($file)) {
            unlink($file);
        }
    }
}
