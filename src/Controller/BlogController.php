<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlogController extends AbstractController
{
    #[Route(
        path: ['en' => '/en/insights', 'fr' => '/fr/blog', 'de' => '/de/magazin', 'es' => '/es/blog', 'nb' => '/nb/innsikt', 'nl' => '/nl/inzichten'],
        name: 'app_blog',
        methods: ['GET'],
    )]
    public function index(): Response
    {
        return $this->render('pages/blog.html.twig');
    }

    #[Route(
        path: ['en' => '/en/insights/how-to-choose-a-manga-drinks-wholesaler', 'fr' => '/fr/blog/choisir-grossiste-boissons-manga', 'de' => '/de/magazin/manga-getraenke-grosshaendler-waehlen', 'es' => '/es/blog/como-elegir-mayorista-bebidas-manga', 'nb' => '/nb/innsikt/velge-grossist-manga-drikke', 'nl' => '/nl/inzichten/manga-dranken-groothandel-kiezen'],
        name: 'app_article_wholesaler',
        methods: ['GET'],
    )]
    public function wholesaler(): Response
    {
        return $this->render('pages/articles/choisir-grossiste.html.twig');
    }

    #[Route(
        path: ['en' => '/en/insights/licensed-products-merchandising', 'fr' => '/fr/blog/merchandising-produits-licence', 'de' => '/de/magazin/merchandising-lizenzprodukte', 'es' => '/es/blog/merchandising-productos-con-licencia', 'nb' => '/nb/innsikt/varepresentasjon-lisensierte-produkter', 'nl' => '/nl/inzichten/merchandising-producten-met-licentie'],
        name: 'app_article_merchandising',
        methods: ['GET'],
    )]
    public function merchandising(): Response
    {
        return $this->render('pages/articles/merchandising.html.twig');
    }

    #[Route(
        path: ['en' => '/en/insights/pop-culture-snacking-trends', 'fr' => '/fr/blog/tendances-snacking-pop-culture', 'de' => '/de/magazin/popkultur-snacking-trends', 'es' => '/es/blog/tendencias-snacking-cultura-pop', 'nb' => '/nb/innsikt/popkultur-snacktrender', 'nl' => '/nl/inzichten/snacking-trends-popcultuur'],
        name: 'app_article_trends',
        methods: ['GET'],
    )]
    public function trends(): Response
    {
        return $this->render('pages/articles/tendances-snacking.html.twig');
    }
}
