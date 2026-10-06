<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    #[Route(
        path: ['en' => '/en/terms-and-conditions', 'fr' => '/fr/conditions-generales-utilisation'],
        name: 'app_terms',
        methods: ['GET'],
    )]
    public function terms(): Response
    {
        return $this->render('pages/legal/terms.html.twig');
    }

    #[Route(
        path: ['en' => '/en/privacy-policy', 'fr' => '/fr/politique-de-confidentialite'],
        name: 'app_privacy',
        methods: ['GET'],
    )]
    public function privacy(): Response
    {
        return $this->render('pages/legal/privacy.html.twig');
    }
}
