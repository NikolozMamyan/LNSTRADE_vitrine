<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogManager;
use App\Entity\Catalog;
use App\Repository\CatalogRepository;
use App\Repository\CatalogShareRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/catalogues-externes', name: 'admin_external_catalog')]
final class AdminExternalCatalogController extends AbstractController
{
    public function __construct(
        private readonly CatalogManager $manager,
        private readonly CatalogRepository $catalogs,
        private readonly CatalogShareRepository $shares,
    ) {
    }

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/external_catalog/index.html.twig', [
            'catalogues' => $this->catalogs->findExternalWithCounts(),
        ]);
    }

    #[Route('/nouveau', name: '_new', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->validateToken('external_catalog_new', $request);
        try {
            $catalog = $this->manager->createExternal($request->request->getString('title'));
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('admin_external_catalog_index');
        }
        $this->addFlash('success', 'Votre catalogue est créé. Importez maintenant votre PDF ou vos images.');

        return $this->redirectToRoute('admin_external_catalog', ['catalogId' => $catalog->getId()]);
    }

    #[Route('/{catalogId}/liens', name: '_share_create', methods: ['POST'], requirements: ['catalogId' => '\\d+'])]
    public function createShare(int $catalogId, Request $request): Response
    {
        $catalog = $this->findExternal($catalogId);
        $this->validateToken('catalog_share_'.$catalogId, $request);
        try {
            $share = $request->isXmlHttpRequest() ? $this->shares->findOneBy(['catalog' => $catalog, 'revokedAt' => null], ['id' => \SortDirection::Descending]) : null;
            $share ??= $this->manager->createShare($catalog, $request->request->getString('label'));
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => true, 'enabled' => $catalog->isEnabled(), 'url' => $this->generateUrl('app_catalog_share', ['token' => $share->getToken()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)]);
            }
            $this->addFlash('success', 'Le lien de partage est prêt. Copiez-le pour l’envoyer à votre client.');
        } catch (\InvalidArgumentException $exception) {
            if ($request->isXmlHttpRequest()) return $this->json(['success' => false, 'message' => $exception->getMessage()], 422);
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_external_catalog', ['catalogId' => $catalogId, '_fragment' => 'partage']);
    }

    #[Route('/{catalogId}/activation', name: '_toggle', methods: ['POST'], requirements: ['catalogId' => '\\d+'])]
    public function toggle(int $catalogId, Request $request): Response
    {
        $catalog = $this->findExternal($catalogId);
        $this->validateToken('external_catalog_toggle_'.$catalogId, $request);
        $this->manager->saveSettings($catalog, $catalog->getTitle(), $request->request->getBoolean('enabled'));
        return $this->json(['success' => true, 'enabled' => $catalog->isEnabled()]);
    }

    #[Route('/{catalogId}/renommer', name: '_rename', methods: ['POST'], requirements: ['catalogId' => '\\d+'])]
    public function rename(int $catalogId, Request $request): Response
    {
        $catalog = $this->findExternal($catalogId);
        $this->validateToken('external_catalog_rename_'.$catalogId, $request);
        try {
            $this->manager->saveSettings($catalog, $request->request->getString('title'), $catalog->isEnabled());
            $this->addFlash('success', 'Le catalogue a été renommé.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('admin_external_catalog_index');
    }

    #[Route('/{catalogId}/liens/{shareId}/desactiver', name: '_share_revoke', methods: ['POST'], requirements: ['catalogId' => '\\d+', 'shareId' => '\\d+'])]
    public function revokeShare(int $catalogId, int $shareId, Request $request): Response
    {
        $this->findExternal($catalogId);
        $share = $this->shares->find($shareId);
        if (null === $share || $share->getCatalog()->getId() !== $catalogId) {
            throw $this->createNotFoundException();
        }
        $this->validateToken('catalog_share_revoke_'.$shareId, $request);
        $this->manager->revokeShare($share);
        $this->addFlash('success', 'Ce lien est désactivé. Les autres liens restent utilisables.');

        return $this->redirectToRoute('admin_external_catalog', ['catalogId' => $catalogId, '_fragment' => 'partage']);
    }

    #[Route('/{catalogId}/supprimer', name: '_delete', methods: ['POST'], requirements: ['catalogId' => '\\d+'])]
    public function delete(int $catalogId, Request $request): Response
    {
        $catalog = $this->findExternal($catalogId);
        $this->validateToken('external_catalog_delete_'.$catalogId, $request);
        $this->manager->deleteExternal($catalog);
        $this->addFlash('success', 'Le catalogue et ses liens de partage ont été supprimés.');

        return $this->redirectToRoute('admin_external_catalog_index');
    }

    private function findExternal(int $id): Catalog
    {
        $catalog = $this->catalogs->find($id);
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
