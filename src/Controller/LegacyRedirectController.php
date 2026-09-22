<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class LegacyRedirectController extends AbstractController
{
    private const ROUTES = [
        'index' => 'app_home.fr',
        'trading-import-distribution' => 'app_trading.fr',
        'marque-ultrapop' => 'app_ultrapop.fr',
        'grossiste-snacks-boissons-manga' => 'app_wholesale.fr',
        'blog' => 'app_blog.fr',
        'choisir-grossiste-boissons-manga' => 'app_article_wholesaler.fr',
        'merchandising-produits-licence' => 'app_article_merchandising.fr',
        'tendances-snacking-pop-culture' => 'app_article_trends.fr',
    ];

    #[Route(
        '/{legacyPage}.html',
        name: 'app_legacy_redirect',
        requirements: ['legacyPage' => '(?i:index|trading-import-distribution|marque-ultrapop|grossiste-snacks-boissons-manga|blog|choisir-grossiste-boissons-manga|merchandising-produits-licence|tendances-snacking-pop-culture)'],
        methods: ['GET'],
        priority: -10,
    )]
    public function __invoke(string $legacyPage): RedirectResponse
    {
        return $this->redirectToRoute(self::ROUTES[strtolower($legacyPage)], status: RedirectResponse::HTTP_MOVED_PERMANENTLY);
    }
}
