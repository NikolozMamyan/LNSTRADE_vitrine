<?php

declare(strict_types=1);

namespace App\Visitor;

use App\Entity\PageVisit;
use App\Entity\VisitorSession;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class VisitorReportProvider
{
    public function __construct(private Connection $connection, private EntityManagerInterface $entityManager)
    {
    }

    public function overview(Request $request): array
    {
        $days = $request->query->getInt('days', 30);
        $days = in_array($days, [0, 7, 30, 90], true) ? $days : 30;
        $ip = trim($request->query->getString('ip'));
        $invalidIp = '' !== $ip && false === filter_var($ip, FILTER_VALIDATE_IP);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $today = $now->setTimezone(new \DateTimeZone('Europe/Paris'))->setTime(0, 0);
        $chartDays = 0 === $days ? 30 : $days;
        $start = $today->modify('-'.($chartDays - 1).' days');
        $utc = new \DateTimeZone('UTC');
        $query = $this->entityManager->createQueryBuilder()->from(VisitorSession::class, 'session');
        if (0 !== $days) {
            $query->andWhere('session.lastSeenAt >= :since')->setParameter('since', $start->setTimezone($utc));
        }
        if ('' !== $ip) {
            $query->andWhere('session.ipAddress = :ip')->setParameter('ip', $invalidIp ? 'invalid' : $ip);
        }
        $count = (int) (clone $query)->select('COUNT(session.id)')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($count / 25));
        $page = min($pageCount, max(1, $request->query->getInt('page', 1)));
        $sessions = $query->select('session')->orderBy('session.lastSeenAt', 'DESC')->addOrderBy('session.id', 'DESC')
            ->setFirstResult(($page - 1) * 25)->setMaxResults(25)->getQuery()->getResult();

        $chart = [];
        for ($index = 0; $index < $chartDays; ++$index) {
            $date = $start->modify('+'.$index.' days')->format('Y-m-d');
            $chart[$date] = ['date' => $date, 'sessions' => 0, 'views' => 0];
        }
        $chartParameters = [
            'since' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'until' => $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
        $ipWhere = '' === $ip ? '' : ' AND session.ip_address = :ip';
        if ('' !== $ip) {
            $chartParameters['ip'] = $invalidIp ? 'invalid' : $ip;
        }
        foreach (['sessions' => ['visitor_session session', 'session.started_at'], 'views' => ['page_visit visit JOIN visitor_session session ON session.id = visit.session_id', 'visit.visited_at']] as $metric => [$table, $column]) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT SUBSTR('.$column.', 1, 13) AS hour, COUNT(*) AS total FROM '.$table.' WHERE '.$column.' >= :since AND '.$column.' < :until'.$ipWhere.' GROUP BY hour',
                $chartParameters,
            );
            foreach ($rows as $row) {
                $date = (new \DateTimeImmutable($row['hour'].':00:00', $utc))->setTimezone($today->getTimezone())->format('Y-m-d');
                $chart[$date][$metric] += (int) $row['total'];
            }
        }
        $activeParameters = ['since' => $now->modify('-5 minutes')->format('Y-m-d H:i:s'), 'now' => $now->format('Y-m-d H:i:s')];
        if ('' !== $ip) {
            $activeParameters['ip'] = $invalidIp ? 'invalid' : $ip;
        }
        $activeCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM visitor_session session WHERE last_seen_at >= :since AND last_seen_at <= :now'.$ipWhere,
            $activeParameters,
        );
        $maxValue = max([...array_column($chart, 'views'), ...array_column($chart, 'sessions')]);

        return [
            'sessions' => $sessions, 'sessionCount' => $count, 'page' => $page, 'pageCount' => $pageCount,
            'days' => $days, 'chartDays' => $chartDays, 'ip' => $ip, 'invalidIp' => $invalidIp,
            'activeSince' => $now->modify('-5 minutes'), 'activeCount' => $activeCount,
            'periodSessions' => array_sum(array_column($chart, 'sessions')),
            'periodViews' => array_sum(array_column($chart, 'views')), 'todayViews' => $chart[$today->format('Y-m-d')]['views'],
            'chart' => array_values($chart), 'chartMax' => max(4, (int) ceil($maxValue / 4) * 4), 'updatedAt' => $now,
        ];
    }

    public function history(VisitorSession $session, Request $request): array
    {
        $repository = $this->entityManager->getRepository(PageVisit::class);
        $count = $repository->count(['session' => $session]);
        $pageCount = max(1, (int) ceil($count / 50));
        $page = min($pageCount, max(1, $request->query->getInt('page', 1)));

        return [
            'session' => $session,
            'visits' => $repository->findBy(['session' => $session], ['visitedAt' => 'ASC', 'id' => 'ASC'], 50, ($page - 1) * 50),
            'page' => $page, 'pageCount' => $pageCount,
            'otherSessions' => $this->entityManager->createQueryBuilder()->select('session')->from(VisitorSession::class, 'session')
                ->where('session.visitorKey = :key')->andWhere('session.id != :id')
                ->setParameter('key', $session->getVisitorKey())->setParameter('id', $session->getId())
                ->orderBy('session.startedAt', 'DESC')->setMaxResults(10)->getQuery()->getResult(),
            'updatedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];
    }
}
