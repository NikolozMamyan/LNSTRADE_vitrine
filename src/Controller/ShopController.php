<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\ShopCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ShopController extends AbstractController
{
    #[Route(
        path: ['en' => '/en/shop', 'fr' => '/fr/boutique', 'de' => '/de/shop', 'es' => '/es/tienda', 'nb' => '/nb/butikk', 'nl' => '/nl/winkel'],
        name: 'app_shop',
        methods: ['GET'],
    )]
    public function __invoke(ShopCatalog $catalog, Request $request): Response
    {
        return $this->render('shop/index.html.twig', ['products' => $catalog->products($request->getLocale())], new Response(headers: ['X-Robots-Tag' => 'noindex, nofollow']));
    }
}
