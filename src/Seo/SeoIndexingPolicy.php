<?php

declare(strict_types=1);

namespace App\Seo;

final class SeoIndexingPolicy
{
    private const INDEXABLE_HOSTS = [
        'lnstrade.fr',
        'www.lnstrade.fr',
    ];

    public function isIndexableHost(string $host): bool
    {
        return in_array(strtolower(trim($host)), self::INDEXABLE_HOSTS, true);
    }
}
