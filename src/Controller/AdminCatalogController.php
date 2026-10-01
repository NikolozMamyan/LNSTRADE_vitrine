<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogManager;
use App\Entity\CatalogPage;
use App\Repository\CatalogPageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/catalogue', name: 'admin_catalog')]
final class AdminCatalogController extends AbstractController
{
    public function __construct(
        private readonly CatalogManager $manager,
        private readonly CatalogPageRepository $pages,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $catalog = $this->manager->current();

        return $this->render('admin/catalog/index.html.twig', [
            'adminSection' => 'catalog',
            'catalog' => $catalog,
            'catalogPages' => $this->pages->findForCatalog($catalog),
        ]);
    }

    #[Route('/apercu', name: '_preview', methods: ['GET'])]
    public function preview(): Response
    {
        $catalog = $this->manager->current();
        $visiblePages = $this->pages->findForCatalog($catalog, true);
        if ([] === $visiblePages) {
            $this->addFlash('error', 'Activez au moins une page avant d’ouvrir l’aperçu.');

            return $this->redirectToRoute('admin_catalog');
        }

        return $this->render('catalog/index.html.twig', [
            'catalog' => $catalog,
            'catalogPages' => $visiblePages,
            'isPreview' => true,
        ]);
    }

    #[Route('/parametres', name: '_settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $this->validateToken('catalog_settings', $request);
        try {
            $this->manager->saveSettings(
                $this->manager->current(),
                $request->request->getString('title'),
                $request->request->getBoolean('enabled'),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->mutationResponse($request, $exception->getMessage(), success: false);
        }

        return $this->mutationResponse($request, 'Les paramètres du catalogue ont été enregistrés.');
    }

    #[Route('/ajouter-images', name: '_add_images', methods: ['POST'])]
    public function addImages(Request $request): Response
    {
        $this->validateToken('catalog_add_images', $request);
        try {
            $count = $this->manager->addImages($this->manager->current(), $request->files->all('images'));
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return $this->mutationResponse($request, $exception->getMessage(), success: false);
        }

        return $this->mutationResponse($request, sprintf('%d page%s ajoutée%s au catalogue.', $count, $count > 1 ? 's' : '', $count > 1 ? 's' : ''), addedCount: $count);
    }

    #[Route('/importer-pdf', name: '_import_pdf', methods: ['POST'])]
    public function importPdf(Request $request): Response
    {
        $this->validateToken('catalog_import_pdf', $request);
        $file = $request->files->get('pdf');
        if (!$file instanceof UploadedFile) {
            return $this->mutationResponse($request, 'Sélectionnez un fichier PDF.', success: false);
        }

        try {
            $count = $this->manager->addPdf(
                $this->manager->current(),
                $file,
                $request->request->getInt('pdfPageCount'),
                $request->request->getString('pdfTitle'),
            );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return $this->mutationResponse($request, $exception->getMessage(), success: false);
        }

        return $this->mutationResponse($request, sprintf('Le PDF a été importé en %d page%s.', $count, $count > 1 ? 's' : ''), addedCount: $count);
    }

    #[Route('/page/{id}', name: '_page_update', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function updatePage(int $id, Request $request): Response
    {
        $page = $this->findPage($id);
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

    #[Route('/page/{id}/deplacer/{direction}', name: '_page_move', methods: ['POST'], requirements: ['id' => '\\d+', 'direction' => 'up|down'])]
    public function movePage(int $id, string $direction, Request $request): Response
    {
        $page = $this->findPage($id);
        $this->validateToken('catalog_move_'.$id, $request);
        $this->manager->move($page, $direction);

        return $this->mutationResponse($request, 'La page a été déplacée.', fragment: 'pages');
    }

    #[Route('/page/{id}/supprimer', name: '_page_delete', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function deletePage(int $id, Request $request): Response
    {
        $page = $this->findPage($id);
        $this->validateToken('catalog_delete_'.$id, $request);
        $this->manager->delete($page);

        return $this->mutationResponse($request, 'La page a été supprimée du catalogue.', fragment: 'pages');
    }

    /** @param list<CatalogPage> $changedPages */
    private function mutationResponse(Request $request, string $message, bool $success = true, array $changedPages = [], int $addedCount = 0, string $fragment = ''): Response
    {
        if (!$request->isXmlHttpRequest()) {
            $this->addFlash($success ? 'success' : 'error', $message);

            return $this->redirectToRoute('admin_catalog', '' === $fragment ? [] : ['_fragment' => $fragment]);
        }
        if (!$success) {
            return $this->json(['success' => false, 'message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $catalog = $this->manager->current();
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

    private function findPage(int $id): CatalogPage
    {
        $page = $this->pages->find($id);
        if (!$page instanceof CatalogPage || $page->getCatalog()->getId() !== $this->manager->current()->getId()) {
            throw $this->createNotFoundException('Page de catalogue introuvable.');
        }

        return $page;
    }

    private function validateToken(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Le formulaire a expiré.');
        }
    }
}
