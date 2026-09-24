<?php

declare(strict_types=1);

namespace App\Seo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SitemapGenerator
{
    private const PAGES = [
        ['template' => 'pages/home.html.twig', 'routes' => ['en' => 'app_home.en', 'fr' => 'app_home.fr']],
        ['template' => 'pages/trading.html.twig', 'routes' => ['en' => 'app_trading.en', 'fr' => 'app_trading.fr']],
        ['template' => 'pages/ultrapop.html.twig', 'routes' => ['en' => 'app_ultrapop.en', 'fr' => 'app_ultrapop.fr']],
        ['template' => 'pages/wholesale.html.twig', 'routes' => ['en' => 'app_wholesale.en', 'fr' => 'app_wholesale.fr']],
        ['template' => 'pages/blog.html.twig', 'routes' => ['en' => 'app_blog.en', 'fr' => 'app_blog.fr']],
        ['template' => 'pages/articles/choisir-grossiste.html.twig', 'routes' => ['en' => 'app_article_wholesaler.en', 'fr' => 'app_article_wholesaler.fr']],
        ['template' => 'pages/articles/merchandising.html.twig', 'routes' => ['en' => 'app_article_merchandising.en', 'fr' => 'app_article_merchandising.fr']],
        ['template' => 'pages/articles/tendances-snacking.html.twig', 'routes' => ['en' => 'app_article_trends.en', 'fr' => 'app_article_trends.fr']],
    ];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $baseUrl,
    ) {
    }

    /**
     * @return list<array{
     *     loc: string,
     *     lastmod: string,
     *     alternates: array{en: string, fr: string, x-default: string}
     * }>
     */
    public function generate(): array
    {
        $urls = [];

        foreach (self::PAGES as $page) {
            $alternates = [];
            foreach ($page['routes'] as $locale => $route) {
                $alternates[$locale] = $this->absoluteUrl($route);
            }
            $alternates['x-default'] = $alternates['en'];

            foreach ($page['routes'] as $locale => $route) {
                $urls[] = [
                    'loc' => $this->absoluteUrl($route),
                    'lastmod' => $this->pageLastModified($page['template'], $locale)->format('Y-m-d'),
                    'alternates' => $alternates,
                ];
            }
        }

        usort($urls, static fn (array $left, array $right): int => $left['loc'] <=> $right['loc']);

        return $urls;
    }

    public function lastModified(): \DateTimeImmutable
    {
        $timestamps = [];
        foreach (self::PAGES as $page) {
            foreach ($page['routes'] as $locale => $_route) {
                $timestamps[] = $this->pageLastModified($page['template'], $locale)->getTimestamp();
            }
        }

        return (new \DateTimeImmutable('@'.max($timestamps)))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function absoluteUrl(string $route): string
    {
        return rtrim($this->baseUrl, '/').$this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    private function pageLastModified(string $template, string $locale): \DateTimeImmutable
    {
        $timestamps = array_filter([
            $this->fileModifiedAt($this->projectDir.'/templates/'.$template),
            $this->fileModifiedAt($this->projectDir.'/translations/messages.'.$locale.'.json'),
        ]);
        $timestamp = $timestamps ? max($timestamps) : time();

        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function fileModifiedAt(string $path): int
    {
        return is_file($path) ? (int) filemtime($path) : 0;
    }
}
