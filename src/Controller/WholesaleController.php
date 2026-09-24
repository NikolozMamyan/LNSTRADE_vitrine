<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WholesaleController extends AbstractController
{
    #[Route(
        path: ['en' => '/en/manga-snacks-drinks-wholesaler', 'fr' => '/fr/grossiste-snacks-boissons-manga'],
        name: 'app_wholesale',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->render('pages/wholesale.html.twig');
    }
}
