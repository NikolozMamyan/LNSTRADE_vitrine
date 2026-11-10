<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\CatalogPage;
use App\Entity\CatalogPageElement;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CatalogElementManager
{
    public function __construct(private EntityManagerInterface $entityManager, private CatalogManager $catalogs) {}

    public function serialize(CatalogPage $page): array
    {
        return array_values(array_map(static fn (CatalogPageElement $element): array => [
            'kind' => $element->getKind(), 'url' => $element->getUrl(), 'label' => $element->getLabel(),
            'x' => $element->getX(), 'y' => $element->getY(), 'width' => $element->getWidth(), 'height' => $element->getHeight(),
        ], $page->getElements()->toArray()));
    }

    public function save(CatalogPage $page, array $rows): void
    {
        if (count($rows) > 100) throw new \InvalidArgumentException('Une page peut contenir au maximum 100 éléments.');
        $elements = [];
        foreach (array_values($rows) as $position => $row) {
            if (!is_array($row) || !in_array($row['kind'] ?? null, ['link', 'video'], true) || !is_string($row['url'] ?? null) || !is_string($row['label'] ?? null)) {
                throw new \InvalidArgumentException('Élément interactif invalide.');
            }
            $label = trim($row['label']);
            if (mb_strlen($label) > 160) throw new \InvalidArgumentException('Le libellé est limité à 160 caractères.');
            $geometry = [];
            foreach (['x', 'y', 'width', 'height'] as $key) {
                if (!is_numeric($row[$key] ?? null) || !is_finite((float) $row[$key]) || $row[$key] < 0 || $row[$key] > 100) {
                    throw new \InvalidArgumentException('La position et la taille doivent être comprises entre 0 et 100 %.');
                }
                $geometry[$key] = round((float) $row[$key], 3);
            }
            if ($geometry['width'] < 1 || $geometry['height'] < 1 || $geometry['x'] + $geometry['width'] > 100.001 || $geometry['y'] + $geometry['height'] > 100.001) {
                throw new \InvalidArgumentException('L’élément doit rester entièrement dans la page, avec une taille minimale de 1 %.');
            }
            $url = 'video' === $row['kind'] ? $this->videoUrl($row['url']) : $this->catalogs->normaliseLink($row['url']);
            if (null === $url) throw new \InvalidArgumentException('Ajoutez une destination à votre lien.');
            $elements[] = new CatalogPageElement($page, $row['kind'], $url, $label, $geometry['x'], $geometry['y'], $geometry['width'], $geometry['height'], $position);
        }
        $page->getElements()->clear();
        foreach ($elements as $element) $page->getElements()->add($element);
        $page->touch();
        $page->getCatalog()->touch();
        $this->entityManager->flush();
    }

    private function videoUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (mb_strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || 'https' !== ($parts['scheme'] ?? '') || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Utilisez une URL vidéo HTTPS valide.');
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be'], true)) {
            $id = 'youtu.be' === $host ? trim($path, '/') : ($query['v'] ?? preg_replace('#^/(?:embed|shorts)/#', '', $path));
            if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) return 'https://www.youtube-nocookie.com/embed/'.$id;
        }
        if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true) && preg_match('#^/(?:video/)?([0-9]+)$#D', $path, $match)) return 'https://player.vimeo.com/video/'.$match[1];
        if (($host === 'fast.wistia.net' || str_ends_with($host, '.wistia.com')) && preg_match('#^/(?:medias|embed/iframe)/([a-z0-9]+)$#D', $path, $match)) return 'https://fast.wistia.net/embed/iframe/'.$match[1];
        if (preg_match('/\.(mp4|webm)$/iD', $path)) return $url;
        throw new \InvalidArgumentException('Ajoutez un lien YouTube, Vimeo, Wistia, ou un fichier MP4 / WebM en HTTPS.');
    }
}
