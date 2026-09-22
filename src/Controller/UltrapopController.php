<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UltrapopController extends AbstractController
{
    #[Route(
        path: ['en' => '/en/ultrapop-brand', 'fr' => '/fr/marque-ultrapop'],
        name: 'app_ultrapop',
        methods: ['GET'],
        options: ['sitemap' => ['priority' => 0.9, 'changefreq' => 'monthly', 'template' => 'pages/ultrapop.html.twig']],
    )]
    public function __invoke(): Response
    {
        return $this->render('pages/ultrapop.html.twig');
    }
}
