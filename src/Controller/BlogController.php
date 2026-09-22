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
        options: ['sitemap' => ['priority' => 0.8, 'changefreq' => 'weekly', 'template' => 'pages/blog.html.twig']],
    )]
    public function index(): Response
    {
        return $this->render('pages/blog.html.twig');
    }

    #[Route(
        path: ['en' => '/en/insights/how-to-choose-a-manga-drinks-wholesaler', 'fr' => '/fr/blog/choisir-grossiste-boissons-manga'],
        name: 'app_article_wholesaler',
        methods: ['GET'],
        options: ['sitemap' => ['priority' => 0.7, 'changefreq' => 'monthly', 'template' => 'pages/articles/choisir-grossiste.html.twig']],
    )]
    public function wholesaler(): Response
    {
        return $this->render('pages/articles/choisir-grossiste.html.twig');
    }

    #[Route(
        path: ['en' => '/en/insights/licensed-products-merchandising', 'fr' => '/fr/blog/merchandising-produits-licence'],
        name: 'app_article_merchandising',
        methods: ['GET'],
        options: ['sitemap' => ['priority' => 0.7, 'changefreq' => 'monthly', 'template' => 'pages/articles/merchandising.html.twig']],
    )]
    public function merchandising(): Response
    {
        return $this->render('pages/articles/merchandising.html.twig');
    }

    #[Route(
        path: ['en' => '/en/insights/pop-culture-snacking-trends', 'fr' => '/fr/blog/tendances-snacking-pop-culture'],
        name: 'app_article_trends',
        methods: ['GET'],
        options: ['sitemap' => ['priority' => 0.7, 'changefreq' => 'monthly', 'template' => 'pages/articles/tendances-snacking.html.twig']],
    )]
    public function trends(): Response
    {
        return $this->render('pages/articles/tendances-snacking.html.twig');
    }
}
