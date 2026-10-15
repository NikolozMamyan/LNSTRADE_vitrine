<?php

declare(strict_types=1);

namespace App\Visitor;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class IpGeolocation
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        #[Autowire(service: 'limiter.ip_geolocation')]
        private RateLimiterFactory $limiter,
        #[Autowire('%env(default::IP_API_KEY)%')]
        private ?string $apiKey,
    ) {
    }

    public function lookup(string $ip): ?array
    {
        return $this->lookupMany([$ip])[$ip];
    }

    public function lookupMany(array $ips): array
    {
        $locations = array_fill_keys($ips, null);
        $pending = [];
        $paused = $this->cache->getItem('visitor_ip_api_pause')->isHit();
        $key = trim($this->apiKey ?? '');
        $endpoint = '' === $key ? 'http://ip-api.com/json/' : 'https://pro.ip-api.com/json/';

        foreach (array_unique($ips) as $ip) {
            if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                continue;
            }
            $item = $this->cache->getItem('visitor_ip_location_'.hash('sha256', $ip));
            if ($item->isHit()) {
                $locations[$ip] = $item->get();
                continue;
            }
            if ($paused || !$this->limiter->create('ip-api')->consume()->isAccepted()) {
                continue;
            }

            try {
                $pending[$ip] = [$this->httpClient->request('GET', $endpoint.rawurlencode($ip), [
                    'query' => ['lang' => 'fr', ...('' === $key ? [] : ['key' => $key])],
                    'timeout' => 1.5,
                    'max_duration' => 2,
                    'max_redirects' => 0,
                ]), $item];
            } catch (ExceptionInterface) {
                $this->cache->save($item->set(null)->expiresAfter(60));
            }
        }

        foreach ($pending as $ip => [$response, $item]) {
            $location = null;
            try {
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);
                if (429 === $status || '0' === ($headers['x-rl'][0] ?? null)) {
                    $this->cache->save($this->cache->getItem('visitor_ip_api_pause')->set(true)
                        ->expiresAfter(max(1, (int) ($headers['x-ttl'][0] ?? 60))));
                }
                if (200 === $status) {
                    $data = $response->toArray(false);
                    $query = $data['query'] ?? null;
                    if ('success' === ($data['status'] ?? null) && is_string($query)
                        && false !== filter_var($query, FILTER_VALIDATE_IP) && inet_pton($query) === inet_pton($ip)) {
                        $location = array_filter(array_intersect_key($data, array_flip([
                            'country', 'countryCode', 'region', 'regionName', 'city', 'zip',
                            'lat', 'lon', 'timezone', 'isp', 'org', 'as', 'query',
                        ])), static fn (mixed $value): bool => is_string($value) || is_int($value) || is_float($value));
                    }
                }
            } catch (ExceptionInterface) {
                $location = null;
            }
            $this->cache->save($item->set($location)->expiresAfter(null === $location ? 60 : 604800));
            $locations[$ip] = $location;
        }

        return $locations;
    }
}
