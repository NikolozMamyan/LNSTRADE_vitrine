<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\VisitorSession;
use App\Visitor\VisitorReportProvider;
use App\Visitor\VisitorReportExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    #[Route('/admin/visiteurs/export.xlsx', name: 'app_admin_visitors_export', methods: ['GET'])]
    public function export(Request $request, VisitorReportProvider $reports, VisitorReportExporter $exporter): Response
    {
        $report = $reports->overview($request, includeSessions: false);
        $filename = 'lnstrade-visiteurs-'.$report['chart'][0]['date'].'-'.$report['chart'][$report['chartDays'] - 1]['date'].'.xlsx';
        $response = new StreamedResponse(static fn () => $exporter->write($report, 'php://output'), headers: [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename));

        return $response;
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
