<?php

declare(strict_types=1);

namespace App\Seo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final readonly class SitemapGenerator
{
    public function __construct(
        private RouterInterface $router,
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
     *     changefreq: string,
     *     priority: float,
     *     alternates: array<string, string>
     * }>
     */
    public function generate(): array
    {
        $localizedRoutes = [];

        foreach ($this->router->getRouteCollection() as $name => $route) {
            $sitemap = $route->getOption('sitemap');
            $locale = $route->getDefault('_locale');
            $canonicalRoute = $route->getDefault('_canonical_route');

            if (!is_array($sitemap) || !is_string($locale) || !in_array($locale, ['en', 'fr'], true) || !is_string($canonicalRoute)) {
                continue;
            }

            $template = $sitemap['template'] ?? null;
            $templatePaths = [
                is_string($template) ? $this->projectDir.'/templates/'.$template : null,
                $this->projectDir.'/translations/messages.'.$locale.'.json',
                $this->projectDir.'/templates/base.html.twig',
                $this->projectDir.'/templates/partials/_header.html.twig',
                $this->projectDir.'/templates/partials/_footer.html.twig',
            ];
            $modifiedAt = max(array_map(
                static fn (?string $path): int => $path && is_file($path) ? (int) filemtime($path) : 0,
                $templatePaths,
            ));

            $localizedRoutes[$canonicalRoute][$locale] = [
                'name' => $name,
                'loc' => rtrim($this->baseUrl, '/').$this->router->generate($name, [], UrlGeneratorInterface::ABSOLUTE_PATH),
                'lastmod' => date('Y-m-d', $modifiedAt ?: time()),
                'changefreq' => (string) ($sitemap['changefreq'] ?? 'monthly'),
                'priority' => (float) ($sitemap['priority'] ?? 0.5),
            ];
        }

        $urls = [];
        foreach ($localizedRoutes as $routes) {
            $alternates = [];
            foreach ($routes as $locale => $route) {
                $alternates[$locale] = $route['loc'];
            }

            foreach ($routes as $route) {
                $urls[] = [
                    'loc' => $route['loc'],
                    'lastmod' => $route['lastmod'],
                    'changefreq' => $route['changefreq'],
                    'priority' => $route['priority'],
                    'alternates' => $alternates,
                ];
            }
        }

        usort($urls, static fn (array $left, array $right): int => [$right['priority'], $left['loc']] <=> [$left['priority'], $right['loc']]);

        return $urls;
    }
}
