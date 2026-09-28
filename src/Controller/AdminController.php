<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\DashboardProvider;
use App\Entity\AdminUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function __invoke(DashboardProvider $dashboardProvider): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('admin/dashboard.html.twig', [
            'dashboard' => $dashboardProvider->get($user),
        ]);
    }
}
