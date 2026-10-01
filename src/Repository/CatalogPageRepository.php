<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Catalog;
use App\Entity\CatalogPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CatalogPage> */
final class CatalogPageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogPage::class);
    }

    /** @return list<CatalogPage> */
    public function findForCatalog(Catalog $catalog, bool $enabledOnly = false): array
    {
        $builder = $this->createQueryBuilder('page')
            ->andWhere('page.catalog = :catalog')
            ->setParameter('catalog', $catalog)
            ->orderBy('page.position', \SortDirection::Ascending);

        if ($enabledOnly) {
            $builder->andWhere('page.enabled = :enabled')->setParameter('enabled', true);
        }

        return $builder->getQuery()->getResult();
    }

    public function nextPosition(Catalog $catalog): int
    {
        return 1 + (int) $this->createQueryBuilder('page')
            ->select('COALESCE(MAX(page.position), 0)')
            ->andWhere('page.catalog = :catalog')
            ->setParameter('catalog', $catalog)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countImageReferences(string $path): int { return (int) $this->count(['imagePath' => $path]); }
    public function countPdfReferences(string $path): int { return (int) $this->count(['pdfPath' => $path]); }
}
