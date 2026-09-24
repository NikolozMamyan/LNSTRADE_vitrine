<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlogController extends AbstractController
{
    #[Route(
        path: ['en' => '/en/insights', 'fr' => '/fr/blog'],
        name: 'app_blog',
        methods: ['GET'],
    )]
    public function index(): Response
    {
        return $this->render('pages/blog.html.twig');
    }

    #[Route(
        path: ['en' => '/en/insights/how-to-choose-a-manga-drinks-wholesaler', 'fr' => '/fr/blog/choisir-grossiste-boissons-manga'],
        name: 'app_article_wholesaler',
        methods: ['GET'],
    )]
    public function wholesaler(): Response
    {
        return $this->render('pages/articles/choisir-grossiste.html.twig');
    }

    #[Route(
        path: ['en' => '/en/insights/licensed-products-merchandising', 'fr' => '/fr/blog/merchandising-produits-licence'],
        name: 'app_article_merchandising',
        methods: ['GET'],
    )]
    public function merchandising(): Response
    {
        return $this->render('pages/articles/merchandising.html.twig');
    }

    #[Route(
        path: ['en' => '/en/insights/pop-culture-snacking-trends', 'fr' => '/fr/blog/tendances-snacking-pop-culture'],
        name: 'app_article_trends',
        methods: ['GET'],
    )]
    public function trends(): Response
    {
        return $this->render('pages/articles/tendances-snacking.html.twig');
    }
}
