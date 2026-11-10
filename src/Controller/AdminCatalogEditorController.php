<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogElementManager;
use App\Repository\CatalogPageRepository;
use App\Repository\CatalogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/catalogues-externes/{catalogId}', requirements: ['catalogId' => '\\d+'])]
final class AdminCatalogEditorController extends AbstractController
{
    #[Route('/personnaliser', name: 'admin_external_catalog_customize', methods: ['GET'])]
    public function editor(int $catalogId, CatalogRepository $catalogs, CatalogPageRepository $pages, CatalogElementManager $elements): Response
    {
        $catalog = $catalogs->find($catalogId);
        if (null === $catalog || !$catalog->isExternal()) throw $this->createNotFoundException();
        $catalogPages = $pages->findForCatalog($catalog);
        if ([] === $catalogPages) {
            $this->addFlash('error', 'Importez un PDF ou des images avant de personnaliser le catalogue.');
            return $this->redirectToRoute('admin_external_catalog', ['catalogId' => $catalogId]);
        }
        return $this->render('admin/external_catalog/editor.html.twig', [
            'catalog' => $catalog, 'catalogPages' => $catalogPages,
            'elementsByPage' => array_combine(array_map(static fn ($page): int => $page->getId(), $catalogPages), array_map($elements->serialize(...), $catalogPages)),
        ]);
    }

    #[Route('/page/{pageId}/elements', name: 'admin_external_catalog_elements', methods: ['POST'], requirements: ['pageId' => '\\d+'])]
    public function save(int $catalogId, int $pageId, Request $request, CatalogPageRepository $pages, CatalogElementManager $elements): Response
    {
        $page = $pages->find($pageId);
        if (null === $page || !$page->getCatalog()->isExternal() || $page->getCatalog()->getId() !== $catalogId) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('catalog_elements_'.$catalogId, $request->headers->get('X-CSRF-Token'))) throw $this->createAccessDeniedException();
        try {
            $payload = $request->getPayload();
            if (!$payload->has('elements')) throw new \InvalidArgumentException('Les éléments de la page sont requis.');
            $elements->save($page, $payload->all('elements'));
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
        return $this->json(['success' => true, 'elements' => $elements->serialize($page)]);
    }
}
