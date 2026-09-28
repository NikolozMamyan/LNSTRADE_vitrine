<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\AdminUser;
use App\Entity\Prospect;
use App\Security\SessionManager;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DashboardProvider
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SessionManager $sessionManager,
    ) {
    }

    /**
     * @return array{
     *     prospectCount: int,
     *     recentProspectCount: int,
     *     submissionCount: int,
     *     pendingSyncCount: int,
     *     activeSessionCount: int,
     *     recentProspects: list<Prospect>
     * }
     */
    public function get(AdminUser $user): array
    {
        $repository = $this->entityManager->getRepository(Prospect::class);
        $since = new \DateTimeImmutable('-7 days');

        $recentProspectCount = (int) $repository->createQueryBuilder('prospect')
            ->select('COUNT(prospect.id)')
            ->where('prospect.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        $submissionCount = (int) $repository->createQueryBuilder('prospect')
            ->select('COALESCE(SUM(prospect.submissionCount), 0)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'prospectCount' => $repository->count([]),
            'recentProspectCount' => $recentProspectCount,
            'submissionCount' => $submissionCount,
            'pendingSyncCount' => $repository->count(['hubSpotSyncedAt' => null]),
            'activeSessionCount' => count($this->sessionManager->activeSessions($user)),
            'recentProspects' => $repository->findBy([], ['lastSubmittedAt' => 'DESC'], 6),
        ];
    }
}
