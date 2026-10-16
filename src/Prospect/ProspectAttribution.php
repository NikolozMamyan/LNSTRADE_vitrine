<?php

declare(strict_types=1);

namespace App\Prospect;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ProspectAttribution
{
    public function __construct(
        private Connection $connection,
        #[Autowire('%env(APP_SECRET)%')] private string $secret,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(string $origin, ?string $ipAddress, string $userAgent): array
    {
        if (null !== $ipAddress && false !== filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            try {
                $session = $this->connection->fetchAssociative(
                    'SELECT source, medium, campaign FROM visitor_session WHERE visitor_key = :key AND last_seen_at > :since AND last_seen_at <= :now ORDER BY last_seen_at DESC, id DESC LIMIT 1',
                    [
                        'key' => hash_hmac('sha256', $ipAddress."\0".mb_substr($userAgent, 0, 500), $this->secret),
                        'since' => $now->modify('-30 minutes')->format('Y-m-d H:i:s'),
                        'now' => $now->format('Y-m-d H:i:s'),
                    ],
                );
                if (false !== $session) {
                    return $session;
                }
            } catch (Exception $exception) {
                $this->logger->warning('Prospect visitor attribution unavailable.', ['exception' => $exception]);
            }
        }

        parse_str((string) parse_url($origin, PHP_URL_QUERY), $query);
        $source = is_string($query['utm_source'] ?? null) ? trim($query['utm_source']) : '';
        $medium = is_string($query['utm_medium'] ?? null) ? trim($query['utm_medium']) : '';
        $campaign = is_string($query['utm_campaign'] ?? null) ? trim($query['utm_campaign']) : '';

        return [
            'source' => '' !== $source ? $source : (isset($query['gclid']) ? 'google' : null),
            'medium' => '' !== $medium ? $medium : (isset($query['gclid']) ? 'cpc' : null),
            'campaign' => '' !== $campaign ? $campaign : null,
        ];
    }
}
