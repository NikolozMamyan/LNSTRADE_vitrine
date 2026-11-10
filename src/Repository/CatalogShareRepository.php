<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Catalog;
use App\Entity\CatalogShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CatalogShare> */
final class CatalogShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CatalogShare::class);
    }

    /** @return list<CatalogShare> */
    public function findForCatalog(Catalog $catalog): array
    {
        return $this->findBy(['catalog' => $catalog], ['createdAt' => \SortDirection::Descending, 'id' => \SortDirection::Descending]);
    }

    public function findAccessible(string $token): ?CatalogShare
    {
        return $this->createQueryBuilder('share')
            ->innerJoin('share.catalog', 'catalog')->addSelect('catalog')
            ->andWhere('share.token = :token')->setParameter('token', $token)
            ->andWhere('share.revokedAt IS NULL')
            ->andWhere('catalog.external = :external')->setParameter('external', true)
            ->andWhere('catalog.enabled = :enabled')->setParameter('enabled', true)
            ->getQuery()->getOneOrNullResult();
    }
}
