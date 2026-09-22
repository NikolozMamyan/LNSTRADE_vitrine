<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TradingController extends AbstractController
{
    #[Route(
        path: ['en' => '/en/food-trading-import-distribution', 'fr' => '/fr/trading-import-distribution'],
        name: 'app_trading',
        methods: ['GET'],
        options: ['sitemap' => ['priority' => 0.9, 'changefreq' => 'monthly', 'template' => 'pages/trading.html.twig']],
    )]
    public function __invoke(): Response
    {
        return $this->render('pages/trading.html.twig');
    }
}
