<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogManager;
use App\Entity\Catalog;
use App\Entity\CatalogPage;
use App\Repository\CatalogPageRepository;
use App\Repository\CatalogRepository;
use App\Repository\CatalogShareRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminCatalogController extends AbstractController
{
    public function __construct(
        private readonly CatalogManager $manager,
        private readonly CatalogPageRepository $pages,
        private readonly CatalogRepository $catalogs,
        private readonly CatalogShareRepository $shares,
    ) {
    }

    #[Route('/admin/catalogue', name: 'admin_catalog', methods: ['GET'])]
    #[Route('/admin/catalogues-externes/{catalogId}', name: 'admin_external_catalog', methods: ['GET'], requirements: ['catalogId' => '\\d+'])]
    public function index(Request $request): Response
    {
        $catalog = $this->catalog($request);

        return $this->render('admin/catalog/index.html.twig', [
            'adminSection' => 'catalog',
            'catalog' => $catalog,
            'catalogPages' => $this->pages->findForCatalog($catalog),
            'catalogShares' => $catalog->isExternal() ? $this->shares->findForCatalog($catalog) : [],
        ]);
    }

    #[Route('/admin/catalogue/apercu', name: 'admin_catalog_preview', methods: ['GET'])]
    #[Route('/admin/catalogues-externes/{catalogId}/apercu', name: 'admin_external_catalog_preview', methods: ['GET'], requirements: ['catalogId' => '\\d+'])]
    public function preview(Request $request): Response
    {
        $catalog = $this->catalog($request);
        $visiblePages = $this->pages->findForCatalog($catalog, true);
        if ([] === $visiblePages) {
            $this->addFlash('error', 'Activez au moins une page avant d’ouvrir l’aperçu.');

            return $this->redirectToRoute($catalog->isExternal() ? 'admin_external_catalog' : 'admin_catalog', $catalog->isExternal() ? ['catalogId' => $catalog->getId()] : []);
        }

        return $this->render('catalog/index.html.twig', [
            'catalog' => $catalog,
            'catalogPages' => $visiblePages,
            'isPreview' => true,
            'catalog_locale' => $catalog->isExternal() ? $catalog->getLocale() : null,
            'previewAdminRoute' => $catalog->isExternal() ? 'admin_external_catalog' : 'admin_catalog',
            'previewAdminParams' => $catalog->isExternal() ? ['catalogId' => $catalog->getId()] : [],
        ]);
    }

    #[Route('/admin/catalogue/parametres', name: 'admin_catalog_settings', methods: ['POST'])]
    #[Route('/admin/catalogues-externes/{catalogId}/parametres', name: 'admin_external_catalog_settings', methods: ['POST'], requirements: ['catalogId' => '\\d+'])]
    public function settings(Request $request): Response
    {
        $this->validateToken('catalog_settings', $request);
        try {
            $this->manager->saveSettings(
                $this->catalog($request),
                $request->request->getString('title'),
                $request->request->getBoolean('enabled'),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->mutationResponse($request, $exception->getMessage(), success: false);
        }

        return $this->mutationResponse($request, 'Les paramètres du catalogue ont été enregistrés.');
    }

    #[Route('/admin/catalogue/ajouter-images', name: 'admin_catalog_add_images', methods: ['POST'])]
    #[Route('/admin/catalogues-externes/{catalogId}/ajouter-images', name: 'admin_external_catalog_add_images', methods: ['POST'], requirements: ['catalogId' => '\\d+'])]
    public function addImages(Request $request): Response
    {
        $this->validateToken('catalog_add_images', $request);
        try {
            $count = $this->manager->addImages($this->catalog($request), $request->files->all('images'));
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return $this->mutationResponse($request, $exception->getMessage(), success: false);
        }

        return $this->mutationResponse($request, sprintf('%d page%s ajoutée%s au catalogue.', $count, $count > 1 ? 's' : '', $count > 1 ? 's' : ''), addedCount: $count);
    }

    #[Route('/admin/catalogue/importer-pdf', name: 'admin_catalog_import_pdf', methods: ['POST'])]
    #[Route('/admin/catalogues-externes/{catalogId}/importer-pdf', name: 'admin_external_catalog_import_pdf', methods: ['POST'], requirements: ['catalogId' => '\\d+'])]
    public function importPdf(Request $request): Response
    {
        $this->validateToken('catalog_import_pdf', $request);
        $file = $request->files->get('pdf');
        if (!$file instanceof UploadedFile) {
            return $this->mutationResponse($request, 'Sélectionnez un fichier PDF.', success: false);
        }

        try {
            $count = $this->manager->addPdf(
                $this->catalog($request),
                $file,
                $request->request->getInt('pdfPageCount'),
                $request->request->getString('pdfTitle'),
            );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return $this->mutationResponse($request, $exception->getMessage(), success: false);
        }

        return $this->mutationResponse($request, sprintf('Le PDF a été importé en %d page%s.', $count, $count > 1 ? 's' : ''), addedCount: $count);
    }

    #[Route('/admin/catalogue/page/{id}', name: 'admin_catalog_page_update', methods: ['POST'], requirements: ['id' => '\\d+'])]
    #[Route('/admin/catalogues-externes/{catalogId}/page/{id}', name: 'admin_external_catalog_page_update', methods: ['POST'], requirements: ['catalogId' => '\\d+', 'id' => '\\d+'])]
    public function updatePage(int $id, Request $request): Response
    {
        $page = $this->findPage($id, $request);
        $this->validateToken('catalog_page_'.$id, $request);
        $replacement = $request->files->get('media');
        try {
            $this->manager->updatePage(
                $page,
                $request->request->getString('title'),
                $request->request->getString('linkUrl'),
                $request->request->getBoolean('openInNewTab'),
                $request->request->getBoolean('enabled'),
                $replacement instanceof UploadedFile ? $replacement : null,
            );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return $this->mutationResponse($request, $exception->getMessage(), success: false, fragment: 'page-'.$id);
        }

        return $this->mutationResponse($request, sprintf('La page %d a été mise à jour.', $page->getPosition()), changedPages: [$page], fragment: 'page-'.$id);
    }

    #[Route('/admin/catalogue/page/{id}/deplacer/{direction}', name: 'admin_catalog_page_move', methods: ['POST'], requirements: ['id' => '\\d+', 'direction' => 'up|down'])]
    #[Route('/admin/catalogues-externes/{catalogId}/page/{id}/deplacer/{direction}', name: 'admin_external_catalog_page_move', methods: ['POST'], requirements: ['catalogId' => '\\d+', 'id' => '\\d+', 'direction' => 'up|down'])]
    public function movePage(int $id, string $direction, Request $request): Response
    {
        $page = $this->findPage($id, $request);
        $this->validateToken('catalog_move_'.$id, $request);
        $this->manager->move($page, $direction);

        return $this->mutationResponse($request, 'La page a été déplacée.', fragment: 'pages');
    }

    #[Route('/admin/catalogue/page/{id}/supprimer', name: 'admin_catalog_page_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    #[Route('/admin/catalogues-externes/{catalogId}/page/{id}/supprimer', name: 'admin_external_catalog_page_delete', methods: ['POST'], requirements: ['catalogId' => '\\d+', 'id' => '\\d+'])]
    public function deletePage(int $id, Request $request): Response
    {
        $page = $this->findPage($id, $request);
        $this->validateToken('catalog_delete_'.$id, $request);
        $this->manager->delete($page);

        return $this->mutationResponse($request, 'La page a été supprimée du catalogue.', fragment: 'pages');
    }

    /** @param list<CatalogPage> $changedPages */
    private function mutationResponse(Request $request, string $message, bool $success = true, array $changedPages = [], int $addedCount = 0, string $fragment = ''): Response
    {
        if (!$request->isXmlHttpRequest()) {
            $this->addFlash($success ? 'success' : 'error', $message);

            return $this->redirectToRoute(null === $request->attributes->get('catalogId') ? 'admin_catalog' : 'admin_external_catalog', array_filter(['catalogId' => $request->attributes->get('catalogId'), '_fragment' => $fragment], static fn ($value): bool => null !== $value && '' !== $value));
        }
        if (!$success) {
            return $this->json(['success' => false, 'message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $catalog = $this->catalog($request);
        $catalogPages = $this->pages->findForCatalog($catalog);
        if ($addedCount > 0) {
            $changedPages = array_slice($catalogPages, -$addedCount);
        }

        return $this->json([
            'success' => true,
            'message' => $message,
            'order' => array_map(static fn (CatalogPage $page): int => $page->getId(), $catalogPages),
            'pages' => array_map(fn (CatalogPage $page): array => [
                'id' => $page->getId(),
                'html' => $this->renderView('admin/catalog/_page.html.twig', ['catalogPage' => $page, 'catalogPages' => $catalogPages]),
            ], $changedPages),
            'enabled' => $catalog->isEnabled(),
            'updatedAt' => $catalog->getUpdatedAt()->format('d/m/Y à H:i'),
        ]);
    }

    private function findPage(int $id, Request $request): CatalogPage
    {
        $page = $this->pages->find($id);
        if (!$page instanceof CatalogPage || $page->getCatalog()->getId() !== $this->catalog($request)->getId()) {
            throw $this->createNotFoundException('Page de catalogue introuvable.');
        }

        return $page;
    }

    private function catalog(Request $request): Catalog
    {
        if (null === $request->attributes->get('catalogId')) {
            return $this->manager->current();
        }
        $catalog = $this->catalogs->find($request->attributes->getInt('catalogId'));
        if (null === $catalog || !$catalog->isExternal()) {
            throw $this->createNotFoundException();
        }

        return $catalog;
    }

    private function validateToken(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Le formulaire a expiré.');
        }
    }
}
