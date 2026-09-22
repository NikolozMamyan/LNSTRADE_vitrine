<?php

declare(strict_types=1);

namespace App\Controller;

use App\Seo\SitemapGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'], format: 'xml')]
    public function __invoke(SitemapGenerator $sitemap): Response
    {
        return $this->render('sitemap.xml.twig', [
            'urls' => $sitemap->generate(),
        ], new Response(headers: [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]));
    }
}
