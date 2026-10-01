<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Catalog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Catalog> */
final class CatalogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Catalog::class);
    }

    public function findCurrent(): ?Catalog
    {
        return $this->createQueryBuilder('catalog')
            ->orderBy('catalog.id', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
