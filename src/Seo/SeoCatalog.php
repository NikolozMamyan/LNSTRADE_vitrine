<?php

declare(strict_types=1);

namespace App\Seo;

use App\Locale\SiteLocale;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SeoCatalog
{
    private const PAGES = [
        ['route' => 'app_home', 'lastmod' => '2026-10-16'],
        ['route' => 'app_trading', 'lastmod' => '2026-10-16'],
        ['route' => 'app_ultrapop', 'lastmod' => '2026-10-16'],
        ['route' => 'app_wholesale', 'lastmod' => '2026-10-16'],
        ['route' => 'app_blog', 'lastmod' => '2026-10-16'],
        ['route' => 'app_article_wholesaler', 'lastmod' => '2026-10-16'],
        ['route' => 'app_article_merchandising', 'lastmod' => '2026-10-16'],
        ['route' => 'app_article_trends', 'lastmod' => '2026-10-16'],
        ['route' => 'app_terms', 'lastmod' => '2024-04-05'],
        ['route' => 'app_privacy', 'lastmod' => '2026-10-16'],
        ['route' => 'app_legal', 'lastmod' => '2026-10-16'],
        ['route' => 'app_contact', 'lastmod' => '2026-10-16'],
        ['route' => 'app_products', 'lastmod' => '2026-10-16'],
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
        $pages = [];
        foreach (self::PAGES as $page) {
            foreach (SiteLocale::CODES as $locale) {
                $pages[] = [
                    'url' => rtrim($this->seoOrigin, '/').$this->urlGenerator->generate($page['route'].'.'.$locale, [], UrlGeneratorInterface::ABSOLUTE_PATH),
                    'lastmod' => in_array($locale, ['en', 'fr'], true) ? $page['lastmod'] : '2026-10-20',
                ];
            }
        }

        return $pages;
    }
}
