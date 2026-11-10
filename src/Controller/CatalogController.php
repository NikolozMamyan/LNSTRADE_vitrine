<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CatalogPageRepository;
use App\Repository\CatalogRepository;
use App\Repository\CatalogShareRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    #[Route('/catalogue/partage/{token}', name: 'app_catalog_share', methods: ['GET'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function shared(string $token, CatalogShareRepository $shares, CatalogPageRepository $pages): Response
    {
        $share = $shares->findAccessible($token);
        if (null === $share) {
            throw $this->createNotFoundException('Ce lien de catalogue est indisponible.');
        }
        $catalog = $share->getCatalog();
        $visiblePages = $pages->findForCatalog($catalog, true);
        if ([] === $visiblePages) {
            throw $this->createNotFoundException('Ce catalogue est indisponible.');
        }
        $response = $this->render('catalog/index.html.twig', [
            'catalog' => $catalog,
            'catalogPages' => $visiblePages,
            'catalog_locale' => $catalog->getLocale(),
            'isPreview' => false,
            'isShared' => true,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    #[Route(path: ['en' => '/en/product-range', 'fr' => '/fr/catalogue-produits', 'de' => '/de/produktsortiment', 'es' => '/es/catalogo-productos', 'nb' => '/nb/produktutvalg', 'nl' => '/nl/productassortiment'], name: 'app_products', methods: ['GET'])]
    public function products(): Response
    {
        return $this->render('pages/products.html.twig');
    }

    #[Route('/catalogue', name: 'app_catalog', methods: ['GET'])]
    public function __invoke(CatalogRepository $catalogs, CatalogPageRepository $pages): Response
    {
        $catalog = $catalogs->findCurrent();
        if (null === $catalog || !$catalog->isEnabled()) {
            return $this->redirectToRoute('app_locale');
        }

        $visiblePages = $pages->findForCatalog($catalog, true);
        if ([] === $visiblePages) {
            return $this->redirectToRoute('app_locale');
        }

        $response = $this->render('catalog/index.html.twig', [
            'catalog' => $catalog,
            'catalogPages' => $visiblePages,
            'isPreview' => false,
        ]);
        $response->setVary('Accept-Language');

        return $response;
    }
}
