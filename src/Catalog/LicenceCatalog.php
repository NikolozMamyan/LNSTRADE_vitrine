<?php

declare(strict_types=1);

namespace App\Catalog;

final class LicenceCatalog
{
    public const SLUGS = ['one-piece', 'naruto', 'dragon-ball', 'demon-slayer', 'league-of-legends'];

    public function all(): array
    {
        return [
            'one-piece' => ['name' => 'One Piece', 'hero_height' => 779, 'hero_width' => 1200, 'products' => ['ultra-ice-tea', 'komesan', 'gumisan', 'negisan']],
            'naruto' => ['name' => 'Naruto Shippuden', 'hero_height' => 725, 'hero_width' => 1117, 'products' => ['ultra-ice-tea', 'bubble-tea', 'chipsan', 'gumisan']],
            'dragon-ball' => ['name' => 'Dragon Ball', 'hero_height' => 779, 'hero_width' => 1200, 'products' => ['ultra-ice-tea', 'komesan', 'gumisan', 'yokosan']],
            'demon-slayer' => ['name' => 'Demon Slayer', 'hero_height' => 675, 'hero_width' => 1200, 'products' => ['tanjiro', 'nezuko', 'chipsan', 'komesan']],
            'league-of-legends' => ['name' => 'League of Legends', 'hero_height' => 675, 'hero_width' => 1200, 'products' => ['ahri', 'chipsan', 'boissons', 'assortiment']],
        ];
    }
}
