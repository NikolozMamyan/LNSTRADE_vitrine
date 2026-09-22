<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LocaleController extends AbstractController
{
    #[Route('/', name: 'app_locale', methods: ['GET'])]
    public function __invoke(Request $request): RedirectResponse
    {
        $requestedLocale = $request->query->getString('lang');
        $locale = in_array($requestedLocale, ['en', 'fr'], true)
            ? $requestedLocale
            : ($request->getPreferredLanguage(['en', 'fr']) ?? 'en');

        $response = $this->redirectToRoute('app_home.'.$locale);
        $response->setVary('Accept-Language');

        return $response;
    }
}
