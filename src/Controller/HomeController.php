<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route(
        path: ['en' => '/en/', 'fr' => '/fr/'],
        name: 'app_home',
        methods: ['GET'],
        options: ['sitemap' => ['priority' => 1.0, 'changefreq' => 'weekly', 'template' => 'pages/home.html.twig']],
    )]
    public function __invoke(): Response
    {
        return $this->render('pages/home.html.twig');
    }
}
