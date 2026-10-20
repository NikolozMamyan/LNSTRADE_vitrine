<?php

declare(strict_types=1);

namespace App\Locale;

use Symfony\Component\HttpFoundation\Request;

final class SiteLocale
{
    public const CODES = ['en', 'fr', 'de', 'es', 'nb', 'nl'];

    public function preferred(Request $request): string
    {
        foreach ($request->getLanguages() as $language) {
            $locale = strtolower(substr($language, 0, 2));
            $locale = in_array($locale, ['no', 'nn'], true) ? 'nb' : $locale;
            if (in_array($locale, self::CODES, true)) {
                return $locale;
            }
        }

        return 'en';
    }
}
