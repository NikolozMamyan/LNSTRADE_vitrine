<?php

declare(strict_types=1);

namespace App\Seo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SeoCatalog
{
    private const PAGES = [
        ['route' => 'app_home.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_home.fr', 'lastmod' => '2026-10-16'],
        ['route' => 'app_trading.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_trading.fr', 'lastmod' => '2026-10-16'],
        ['route' => 'app_ultrapop.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_ultrapop.fr', 'lastmod' => '2026-10-16'],
        ['route' => 'app_wholesale.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_wholesale.fr', 'lastmod' => '2026-10-16'],
        ['route' => 'app_blog.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_blog.fr', 'lastmod' => '2026-10-16'],
        ['route' => 'app_article_wholesaler.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_article_wholesaler.fr', 'lastmod' => '2026-10-16'],
        ['route' => 'app_article_merchandising.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_article_merchandising.fr', 'lastmod' => '2026-10-16'],
        ['route' => 'app_article_trends.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_article_trends.fr', 'lastmod' => '2026-10-16'],
        ['route' => 'app_terms.en', 'lastmod' => '2024-04-05'],
        ['route' => 'app_terms.fr', 'lastmod' => '2024-04-05'],
        ['route' => 'app_privacy.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_privacy.fr', 'lastmod' => '2026-10-16'],
        ['route' => 'app_legal.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_legal.fr', 'lastmod' => '2026-10-16'],
        ['route' => 'app_contact.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_contact.fr', 'lastmod' => '2026-10-16'],
        ['route' => 'app_products.en', 'lastmod' => '2026-10-16'],
        ['route' => 'app_products.fr', 'lastmod' => '2026-10-16'],
    ];

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(SEO_ORIGIN)%')]
        private string $seoOrigin,
    ) {
    }

    /** @return list<array{url: string, lastmod: string}> */
    public function publicPages(): array
    {
        return array_map(fn (array $page): array => [
            'url' => rtrim($this->seoOrigin, '/').$this->urlGenerator->generate(
                $page['route'],
                [],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            ),
            'lastmod' => $page['lastmod'],
        ], self::PAGES);
    }
}
