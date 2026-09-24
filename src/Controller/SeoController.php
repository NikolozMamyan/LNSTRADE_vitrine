<?php

declare(strict_types=1);

namespace App\Controller;

use App\Seo\SeoCatalog;
use App\Seo\SeoIndexingPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoController extends AbstractController
{
    #[Route('/robots.txt', name: 'app_seo_robots', methods: ['GET'])]
    public function robots(Request $request, SeoIndexingPolicy $indexingPolicy): Response
    {
        $indexable = $indexingPolicy->isIndexableHost($request->getHost());
        $response = $this->textResponse($this->renderView('seo/robots.txt.twig', [
            'indexable' => $indexable,
        ]));

        if (!$indexable) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }

    #[Route('/sitemap.xml', name: 'app_seo_sitemap', methods: ['GET'])]
    public function sitemap(SeoCatalog $seoCatalog): Response
    {
        $response = new Response($this->renderView('seo/sitemap.xml.twig', [
            'pages' => $seoCatalog->publicPages(),
        ]));
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
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

    private function textResponse(string $content): Response
    {
        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
