<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\LicenceCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LicenceController extends AbstractController
{
    #[Route(
        path: [
            'en' => '/en/{licence}-drinks-snacks-wholesale',
            'fr' => '/fr/grossiste-{licence}-boissons-snacks',
            'de' => '/de/{licence}-getraenke-snacks-grosshandel',
            'es' => '/es/mayorista-{licence}-bebidas-snacks',
            'nb' => '/nb/grossist-{licence}-snacks-drikke',
            'nl' => '/nl/{licence}-snacks-dranken-groothandel',
        ],
        name: 'app_licence',
        requirements: ['licence' => 'one-piece|naruto|dragon-ball|demon-slayer|league-of-legends'],
        methods: ['GET'],
    )]
    public function __invoke(string $licence, LicenceCatalog $catalog): Response
    {
        return $this->render('pages/licence.html.twig', [
            'licence_slug' => $licence,
            'licence' => $catalog->all()[$licence],
        ]);
    }
}
