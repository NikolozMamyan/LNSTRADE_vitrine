<?php

declare(strict_types=1);

namespace App\Controller;

use App\Locale\SiteLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LocaleController extends AbstractController
{
    #[Route('/', name: 'app_locale', methods: ['GET'])]
    public function __invoke(Request $request, SiteLocale $siteLocale): RedirectResponse
    {
        $requestedLocale = $request->query->getString('lang');
        $requestedLocale = 'no' === $requestedLocale ? 'nb' : $requestedLocale;
        $locale = in_array($requestedLocale, SiteLocale::CODES, true)
            ? $requestedLocale
            : $siteLocale->preferred($request);

        $parameters = $request->query->all();
        unset($parameters['lang'], $parameters['_locale']);
        $response = $this->redirectToRoute('app_home.'.$locale, $parameters);
        $response->setVary('Accept-Language');

        return $response;
    }
}
