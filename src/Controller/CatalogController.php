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

        return $this->render('catalog/index.html.twig', [
            'catalog' => $catalog,
            'catalogPages' => $visiblePages,
            'isPreview' => false,
        ]);
    }
}
