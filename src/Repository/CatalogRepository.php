<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Catalog;
use App\Entity\CatalogPage;
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
            ->andWhere('catalog.external = :external')->setParameter('external', false)
            ->orderBy('catalog.id', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findExternalWithCounts(): array
    {
        return $this->createQueryBuilder('catalog')
            ->addSelect('COUNT(page.id) AS pageCount')
            ->addSelect('cover.imagePath AS coverImage, cover.pdfPath AS coverPdf, cover.pdfPage AS coverPage')
            ->leftJoin(CatalogPage::class, 'page', 'WITH', 'page.catalog = catalog')
            ->leftJoin(CatalogPage::class, 'cover', 'WITH', 'cover.catalog = catalog AND cover.position = 1')
            ->andWhere('catalog.external = :external')->setParameter('external', true)
            ->groupBy('catalog.id')
            ->addGroupBy('cover.id')
            ->orderBy('catalog.updatedAt', \SortDirection::Descending)
            ->addOrderBy('catalog.id', \SortDirection::Descending)
            ->getQuery()->getResult();
    }
}
