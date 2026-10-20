<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TradingController extends AbstractController
{
    #[Route(
        path: ['en' => '/en/food-trading-import-distribution', 'fr' => '/fr/trading-import-distribution', 'de' => '/de/lebensmittelhandel-import-vertrieb', 'es' => '/es/importacion-distribucion-alimentaria', 'nb' => '/nb/matvarehandel-import-distribusjon', 'nl' => '/nl/levensmiddelenhandel-import-distributie'],
        name: 'app_trading',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->render('pages/trading.html.twig');
    }
}
