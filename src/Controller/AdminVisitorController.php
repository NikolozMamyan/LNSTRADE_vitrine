<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\VisitorSession;
use App\Visitor\VisitorReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminVisitorController extends AbstractController
{
    #[Route('/admin/visiteurs', name: 'app_admin_visitors', methods: ['GET'])]
    public function index(Request $request, VisitorReportProvider $reports): Response
    {
        return $this->render(
            $request->isXmlHttpRequest() ? 'admin/visitors/_overview.html.twig' : 'admin/visitors/index.html.twig',
            $reports->overview($request),
            new Response(headers: ['Cache-Control' => 'private, no-store']),
        );
    }

    #[Route('/admin/visiteurs/{id}', name: 'app_admin_visitor_session', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function session(int $id, Request $request, EntityManagerInterface $entityManager, VisitorReportProvider $reports): Response
    {
        $session = $entityManager->find(VisitorSession::class, $id);
        if (!$session instanceof VisitorSession) {
            throw $this->createNotFoundException('Session introuvable.');
        }

        return $this->render(
            $request->isXmlHttpRequest() ? 'admin/visitors/_history.html.twig' : 'admin/visitors/session.html.twig',
            $reports->history($session, $request),
            new Response(headers: ['Cache-Control' => 'private, no-store']),
        );
    }
}
