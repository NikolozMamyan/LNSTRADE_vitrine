<?php

declare(strict_types=1);

namespace App\Tests\Visitor;

use App\Visitor\IpGeolocation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class IpGeolocationTest extends TestCase
{
    public function testItCachesAndDeduplicatesLookupsAndPreservesCoordinatesAtZero(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringStartsWith('http://ip-api.com/json/212.32.69.206?', $url);
            self::assertStringContainsString('lang=fr', $url);
            self::assertSame(2.0, $options['max_duration']);
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse(json_encode([
                'status' => 'success', 'query' => '212.32.69.206', 'country' => 'États-Unis',
                'countryCode' => 'US', 'city' => 'Dallas', 'lat' => 0, 'lon' => 0,
                'isp' => 'Latitude.sh', 'unexpected' => ['ignored'],
            ], JSON_THROW_ON_ERROR));
        });
        $lookup = $this->lookup($client);
        $result = $lookup->lookupMany(['212.32.69.206', '212.32.69.206']);
        self::assertSame('États-Unis', $result['212.32.69.206']['country']);
        self::assertSame(0, $result['212.32.69.206']['lat']);
        self::assertArrayNotHasKey('unexpected', $result['212.32.69.206']);
        self::assertSame($result['212.32.69.206'], $lookup->lookup('212.32.69.206'));
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testPrivateReservedAndInvalidAddressesNeverLeaveTheServer(): void
    {
        $client = new MockHttpClient(static function (): never { self::fail('Unexpected HTTP request.'); });
        $addresses = ['127.0.0.1', '192.168.1.1', '10.0.0.1', '::1', 'fe80::1', '2001:db8::1', '198.51.100.20', '100.64.0.1', 'invalid', 'https://example.com'];
        self::assertSame(array_fill_keys($addresses, null), $this->lookup($client)->lookupMany($addresses));
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testApiErrorsInvalidJsonAndMismatchedQueriesAreCachedWithoutBreakingThePage(): void
    {
        foreach ([
            new MockResponse('{"status":"fail","message":"invalid query"}'),
            new MockResponse('invalid json'),
            new MockResponse('{"status":"success","query":"1.1.1.1","country":"Wrong country"}'),
            new MockResponse('{}', ['http_code' => 500]),
        ] as $response) {
            $client = new MockHttpClient($response);
            $lookup = $this->lookup($client);
            self::assertNull($lookup->lookup('212.32.69.206'));
            self::assertNull($lookup->lookup('212.32.69.206'));
            self::assertSame(1, $client->getRequestsCount());
        }
    }

    public function testNetworkFailuresDoNotEscapeTheHelper(): void
    {
        $client = new MockHttpClient(static function (): never { throw new TransportException('API unavailable.'); });
        self::assertNull($this->lookup($client)->lookup('212.32.69.206'));
    }

    public function testTheRemoteQuotaPausesNewRequests(): void
    {
        $client = new MockHttpClient(new MockResponse('{"status":"success","query":"212.32.69.206","country":"États-Unis"}', [
            'response_headers' => ['X-Rl: 0', 'X-Ttl: 60'],
        ]));
        $lookup = $this->lookup($client);
        self::assertNotNull($lookup->lookup('212.32.69.206'));
        self::assertNull($lookup->lookup('8.8.8.8'));
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testHttp429PausesNewRequests(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['X-Ttl: 60']]));
        $lookup = $this->lookup($client);
        self::assertNull($lookup->lookup('212.32.69.206'));
        self::assertNull($lookup->lookup('8.8.8.8'));
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testTheLocalQuotaAlsoLimitsColdCacheLookups(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            return new MockResponse(json_encode(['status' => 'success', 'query' => basename(parse_url($url, PHP_URL_PATH)), 'country' => 'Example'], JSON_THROW_ON_ERROR));
        });
        $result = $this->lookup($client, limit: 2)->lookupMany(['8.8.8.8', '1.1.1.1', '9.9.9.9']);
        self::assertNotNull($result['8.8.8.8']);
        self::assertNotNull($result['1.1.1.1']);
        self::assertNull($result['9.9.9.9']);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testAnApiKeyUsesTheHttpsProEndpoint(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertStringStartsWith('https://pro.ip-api.com/json/212.32.69.206?', $url);
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('test-key', $query['key']);

            return new MockResponse('{"status":"success","query":"212.32.69.206","country":"États-Unis"}');
        });
        self::assertNotNull($this->lookup($client, 'test-key')->lookup('212.32.69.206'));
    }

    private function lookup(MockHttpClient $client, ?string $apiKey = null, int $limit = 40): IpGeolocation
    {
        return new IpGeolocation($client, new ArrayAdapter(), new RateLimiterFactory([
            'id' => 'geolocation', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute',
        ], new InMemoryStorage()), $apiKey);
    }
}
