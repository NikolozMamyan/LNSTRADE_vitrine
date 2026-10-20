<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CatalogPageRepository;
use App\Repository\CatalogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
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
