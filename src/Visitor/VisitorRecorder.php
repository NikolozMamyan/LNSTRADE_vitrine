<?php

declare(strict_types=1);

namespace App\Visitor;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final readonly class VisitorRecorder
{
    public function __construct(
        private Connection $connection,
        #[Autowire('%env(APP_SECRET)%')]
        private string $secret,
    ) {
    }

    public function record(Request $request, ?\DateTimeImmutable $now = null): void
    {
        $ip = $request->getClientIp();
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        $agent = mb_substr($request->headers->get('User-Agent', ''), 0, 500);
        $key = hash_hmac('sha256', $ip."\0".$agent, $this->secret);
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s');
        $since = $now->modify('-30 minutes')->format('Y-m-d H:i:s');
        $path = mb_substr($request->getPathInfo(), 0, 1000);

        $this->connection->transactional(function (Connection $connection) use ($request, $ip, $agent, $key, $timestamp, $since, $path): void {
            $id = $connection->fetchOne(
                'SELECT id FROM visitor_session WHERE visitor_key = :key AND last_seen_at > :since AND last_seen_at <= :now ORDER BY last_seen_at DESC, id DESC LIMIT 1',
                ['key' => $key, 'since' => $since, 'now' => $timestamp],
            );

            if (false === $id) {
                $referrer = parse_url($request->headers->get('Referer', ''), PHP_URL_HOST);
                $source = $request->query->getString('utm_source');
                if ('' === $source) {
                    $source = $request->query->has('gclid') ? 'google' : ($referrer && $referrer !== $request->getHost() ? $referrer : 'direct');
                }
                $medium = $request->query->getString('utm_medium', $request->query->has('gclid') ? 'cpc' : '');
                $campaign = $request->query->getString('utm_campaign');

                $connection->insert('visitor_session', [
                    'visitor_key' => $key,
                    'ip_address' => $ip,
                    'user_agent' => $agent,
                    'started_at' => $timestamp,
                    'last_seen_at' => $timestamp,
                    'entry_path' => $path,
                    'current_path' => $path,
                    'page_view_count' => 1,
                    'source' => mb_substr($source, 0, 200),
                    'medium' => '' === $medium ? null : mb_substr($medium, 0, 100),
                    'campaign' => '' === $campaign ? null : mb_substr($campaign, 0, 200),
                ]);
                $id = $connection->lastInsertId();
            } else {
                $connection->executeStatement(
                    'UPDATE visitor_session SET last_seen_at = :now, current_path = :path, page_view_count = page_view_count + 1 WHERE id = :id',
                    ['now' => $timestamp, 'path' => $path, 'id' => $id],
                );
            }

            $connection->insert('page_visit', ['session_id' => $id, 'path' => $path, 'visited_at' => $timestamp]);
        });
    }
}
