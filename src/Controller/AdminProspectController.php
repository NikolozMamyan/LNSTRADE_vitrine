<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Prospect;
use App\HubSpot\HubSpotException;
use App\Prospect\ProspectHubSpotSender;
use App\Prospect\ProspectRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AdminProspectController extends AbstractController
{
    #[Route('/admin/prospects', name: 'app_admin_prospects', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $repository = $entityManager->getRepository(Prospect::class);
        $count = $repository->count([]);
        $pageCount = max(1, (int) ceil($count / 25));
        $page = min($pageCount, max(1, $request->query->getInt('page', 1)));

        return $this->render('admin/prospects.html.twig', [
            'prospects' => $repository->findBy([], ['lastSubmittedAt' => 'DESC', 'id' => 'DESC'], 25, ($page - 1) * 25),
            'prospectCount' => $count,
            'page' => $page,
            'pageCount' => $pageCount,
        ]);
    }

    #[Route('/admin/prospects/{id}/hubspot', name: 'app_admin_prospect_hubspot', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        ProspectHubSpotSender $sender,
        LoggerInterface $logger,
    ): Response {
        if (!$this->isCsrfTokenValid('prospect_hubspot_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Le formulaire a expiré.');
        }

        $prospect = $entityManager->find(Prospect::class, $id);
        if (!$prospect instanceof Prospect) {
            throw $this->createNotFoundException('Prospect introuvable.');
        }

        if (null !== $prospect->getHubSpotRejectedAt()) {
            $this->addFlash('error', 'La synchronisation est refusée pour ce prospect. Réautorisez-la avant l’envoi.');

            return $this->redirectToRoute('app_admin_prospects', ['page' => max(1, $request->request->getInt('page', 1))], Response::HTTP_SEE_OTHER);
        }

        try {
            $sender->send($prospect);
            $this->addFlash('success', 'Le prospect a été envoyé à HubSpot.');
        } catch (HubSpotException $exception) {
            $logger->error('Manual HubSpot submission failed.', ['prospect_id' => $id, 'exception' => $exception]);
            $this->addFlash('error', 'email' === $exception->field
                ? 'HubSpot refuse l’adresse e-mail de ce prospect. Vérifiez l’adresse avant de réessayer. Le prospect reste enregistré.'
                : 'L’envoi à HubSpot a échoué. Le prospect reste enregistré ; vous pouvez réessayer.');
        }

        return $this->redirectToRoute('app_admin_prospects', ['page' => max(1, $request->request->getInt('page', 1))], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/prospects/{id}/hubspot/decision', name: 'app_admin_prospect_hubspot_decision', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decide(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        ProspectRecorder $recorder,
    ): Response {
        if (!$this->isCsrfTokenValid('prospect_hubspot_decision_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Le formulaire a expiré.');
        }

        $decision = $request->request->getString('decision');
        if (!in_array($decision, ['reject', 'allow'], true)) {
            throw new BadRequestHttpException('Décision de synchronisation invalide.');
        }

        $prospect = $entityManager->find(Prospect::class, $id);
        if (!$prospect instanceof Prospect) {
            throw $this->createNotFoundException('Prospect introuvable.');
        }
        if (null !== $prospect->getHubSpotSyncedAt()) {
            throw new ConflictHttpException('Ce prospect est déjà synchronisé avec HubSpot.');
        }

        $rejected = 'reject' === $decision;
        $recorder->setHubSpotRejected($prospect, $rejected);
        $this->addFlash('success', $rejected
            ? 'Synchronisation refusée. Le prospect est conservé dans votre base et son envoi à HubSpot est bloqué.'
            : 'Synchronisation réautorisée. Vous pouvez maintenant envoyer ce prospect à HubSpot.');

        return $this->redirectToRoute('app_admin_prospects', ['page' => max(1, $request->request->getInt('page', 1))], Response::HTTP_SEE_OTHER);
    }
}
