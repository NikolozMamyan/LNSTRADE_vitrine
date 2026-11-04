<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Product> */
final class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return list<Product> */
    public function published(): array
    {
        return $this->createQueryBuilder('product')->addSelect('image', 'translation')
            ->leftJoin('product.images', 'image')->leftJoin('product.translations', 'translation')
            ->where('product.published = true')->orderBy('product.sortOrder', \SortDirection::Ascending)->addOrderBy('product.id', \SortDirection::Ascending)
            ->addOrderBy('image.position', \SortDirection::Ascending)->addOrderBy('image.id', \SortDirection::Ascending)->getQuery()->getResult();
    }

    public function search(string $search, int $page, int $pageSize = 30): array
    {
        $query = $this->createQueryBuilder('product');
        if ('' !== $search) {
            $query->where('LOWER(product.name) LIKE :search OR LOWER(product.reference) LIKE :search OR LOWER(product.license) LIKE :search OR product.ean LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }
        $count = (int) (clone $query)->select('COUNT(product.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($count / $pageSize));
        $page = max(1, min($pages, $page));
        $items = $query->orderBy('product.sortOrder', \SortDirection::Ascending)->addOrderBy('product.id', \SortDirection::Ascending)
            ->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize)->getQuery()->getResult();

        return ['items' => $items, 'total' => $count, 'page' => $page, 'pages' => $pages];
    }
}
