<?php

declare(strict_types=1);

namespace App\Seo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SeoCatalog
{
    private const PAGES = [
        ['route' => 'app_home.en', 'changefreq' => 'weekly', 'priority' => '1.0'],
        ['route' => 'app_home.fr', 'changefreq' => 'weekly', 'priority' => '1.0'],
        ['route' => 'app_trading.en', 'changefreq' => 'monthly', 'priority' => '0.9'],
        ['route' => 'app_trading.fr', 'changefreq' => 'monthly', 'priority' => '0.9'],
        ['route' => 'app_ultrapop.en', 'changefreq' => 'monthly', 'priority' => '0.9'],
        ['route' => 'app_ultrapop.fr', 'changefreq' => 'monthly', 'priority' => '0.9'],
        ['route' => 'app_wholesale.en', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['route' => 'app_wholesale.fr', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['route' => 'app_blog.en', 'changefreq' => 'weekly', 'priority' => '0.8'],
        ['route' => 'app_blog.fr', 'changefreq' => 'weekly', 'priority' => '0.8'],
        ['route' => 'app_article_wholesaler.en', 'changefreq' => 'monthly', 'priority' => '0.7'],
        ['route' => 'app_article_wholesaler.fr', 'changefreq' => 'monthly', 'priority' => '0.7'],
        ['route' => 'app_article_merchandising.en', 'changefreq' => 'monthly', 'priority' => '0.7'],
        ['route' => 'app_article_merchandising.fr', 'changefreq' => 'monthly', 'priority' => '0.7'],
        ['route' => 'app_article_trends.en', 'changefreq' => 'monthly', 'priority' => '0.7'],
        ['route' => 'app_article_trends.fr', 'changefreq' => 'monthly', 'priority' => '0.7'],
    ];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(SEO_ORIGIN)%')]
        private string $seoOrigin,
    ) {
    }

    /** @return list<array{url: string, changefreq: string, priority: string}> */
    public function publicPages(): array
    {
        return array_map(fn (array $page): array => [
            'url' => rtrim($this->seoOrigin, '/').$this->urlGenerator->generate(
                $page['route'],
                [],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            ),
            'changefreq' => $page['changefreq'],
            'priority' => $page['priority'],
        ], self::PAGES);
    }
}
