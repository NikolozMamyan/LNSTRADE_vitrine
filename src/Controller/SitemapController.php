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
        $content = $this->renderView('sitemap.xml.twig', [
            'urls' => $sitemap->generate(),
        ]);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    #[Route('/wp-sitemap.xml', name: 'app_legacy_wordpress_sitemap', methods: ['GET'])]
    #[Route(
        '/wp-sitemap-{type}.xml',
        name: 'app_legacy_wordpress_sitemap_detail',
        requirements: ['type' => '[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function legacyWordPressSitemap(): Response
    {
        return new Response(status: Response::HTTP_GONE, headers: [
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
