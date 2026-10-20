<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    #[Route(path: ['en' => '/en/legal-notice', 'fr' => '/fr/mentions-legales', 'de' => '/de/impressum', 'es' => '/es/aviso-legal', 'nb' => '/nb/juridisk-informasjon', 'nl' => '/nl/juridische-informatie'], name: 'app_legal', methods: ['GET'])]
    public function notice(): Response
    {
        return $this->render('pages/legal/notice.html.twig');
    }

    #[Route(
        path: ['en' => '/en/terms-and-conditions', 'fr' => '/fr/conditions-generales-utilisation', 'de' => '/de/nutzungsbedingungen', 'es' => '/es/condiciones-de-uso', 'nb' => '/nb/bruksvilkar', 'nl' => '/nl/gebruiksvoorwaarden'],
        name: 'app_terms',
        methods: ['GET'],
    )]
    public function terms(): Response
    {
        return $this->render('pages/legal/terms.html.twig');
    }

    #[Route(
        path: ['en' => '/en/privacy-policy', 'fr' => '/fr/politique-de-confidentialite', 'de' => '/de/datenschutz', 'es' => '/es/politica-de-privacidad', 'nb' => '/nb/personvern', 'nl' => '/nl/privacybeleid'],
        name: 'app_privacy',
        methods: ['GET'],
    )]
    public function privacy(): Response
    {
        return $this->render('pages/legal/privacy.html.twig');
    }
}
