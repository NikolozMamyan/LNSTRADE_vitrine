<?php

declare(strict_types=1);

namespace App\Tests\HubSpot;

use App\Entity\Prospect;
use App\Entity\PageVisit;
use App\Entity\VisitorSession;
use App\HubSpot\HubSpotGateway;
use App\Visitor\IpGeolocation;
use App\Visitor\VisitorRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ContactControllerTest extends WebTestCase
{
    public function testItConfirmsTheSavedSubmissionWithoutCallingHubSpot(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $entityManager->getConnection()->getParams()['driver']);
        (new SchemaTool($entityManager))->createSchema(array_map($entityManager->getClassMetadata(...), [Prospect::class, VisitorSession::class]));

        $hubSpot = $this->createMock(HubSpotGateway::class);
        $hubSpot->expects(self::never())->method('submit');
        $container->set(HubSpotGateway::class, $hubSpot);
        $httpClient = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertStringStartsWith('http://ip-api.com/json/212.32.69.206?', $url);

            return new MockResponse('{"status":"success","query":"212.32.69.206","countryCode":"US","country":"États-Unis"}');
        });
        $container->set(IpGeolocation::class, new IpGeolocation(
            $httpClient,
            new ArrayAdapter(),
            new RateLimiterFactory(['id' => 'prospect-geolocation', 'policy' => 'fixed_window', 'limit' => 40, 'interval' => '1 minute'], new InMemoryStorage()),
            null,
        ));

        $crawler = $client->request('GET', '/fr/');
        $client->request('POST', '/contact', [
            '_csrf_token' => $crawler->filter('#contact-form input[name="_csrf_token"]')->attr('value'),
            'name' => 'Alice Martin',
            'company' => 'Example SAS',
            'email' => 'alice@example.com',
            'locale' => 'fr',
            'ipAddress' => '8.8.8.8',
            'countryCode' => 'FR',
        ], server: ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '212.32.69.206']);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"ok":true,"submitted":true}',
            (string) $client->getResponse()->getContent(),
        );
        $prospect = $entityManager->getRepository(Prospect::class)->findOneBy(['email' => 'alice@example.com']);
        self::assertInstanceOf(Prospect::class, $prospect);
        self::assertNull($prospect->getHubSpotSyncedAt());
        self::assertSame('Alice Martin', $prospect->getName());
        self::assertSame(1, $prospect->getSubmissionCount());
        $id = $prospect->getId();
        $entityManager->clear();
        $prospect = $entityManager->find(Prospect::class, $id);
        self::assertSame('212.32.69.206', $prospect->getIpAddress());
        self::assertSame('US', $prospect->getCountryCode());
        self::assertSame('United States', $prospect->getCountryName());
        self::assertSame('US', $prospect->toContactSubmission()->countryCode);
    }

    public function testItRejectsInvalidContactDataBeforeCallingHubSpot(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/');
        $token = $crawler->filter('#contact-form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/contact', [
            '_csrf_token' => $token,
            'locale' => 'fr',
            'origin' => 'https://lnstrade.fr/fr/',
            'context' => 'Distribution / grossiste',
            'name' => 'Alice Martin',
            'company' => 'Example SAS',
            'email' => 'adresse-invalide',
            'phone' => '',
            'vat' => '',
            'message' => '',
            'website' => '',
        ], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertJsonStringEqualsJsonString(
            '{"ok":false,"error":"validation","fields":["email"]}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testItKeepsTheVisitorSourceAfterNavigationAndReplacesItForTheNextEnquiry(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $entityManager->getConnection()->getParams()['driver']);
        (new SchemaTool($entityManager))->createSchema(array_map($entityManager->getClassMetadata(...), [Prospect::class, VisitorSession::class, PageVisit::class]));
        $client->setServerParameter('REMOTE_ADDR', '198.51.100.20');
        $client->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 Attribution test');
        $recorder = static::getContainer()->get(VisitorRecorder::class);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $server = ['REMOTE_ADDR' => '198.51.100.20', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Attribution test'];
        $recorder->record(Request::create('/fr/?utm_source=google&utm_medium=cpc&utm_campaign=lnstrade_europe', server: $server), $now->modify('-5 minutes'));
        $recorder->record(Request::create('/en/manga-snacks-drinks-wholesaler', server: $server), $now->modify('-2 minutes'));
        $recorder->record(Request::create('/fr/?utm_source=other_browser&utm_campaign=wrong_campaign', server: array_replace($server, ['HTTP_USER_AGENT' => 'Another browser'])), $now->modify('-1 minute'));
        $crawler = $client->request('GET', '/en/manga-snacks-drinks-wholesaler');
        $data = [
            '_csrf_token' => $crawler->filter('#contact-form input[name="_csrf_token"]')->attr('value'),
            'name' => 'Alice Martin', 'company' => 'Example SAS', 'email' => 'alice@example.com', 'locale' => 'en',
            'origin' => 'https://lnstrade.fr/en/manga-snacks-drinks-wholesaler', 'source' => 'forged', 'campaign' => 'forged',
        ];
        $client->request('POST', '/contact', $data);
        self::assertResponseIsSuccessful();
        $entityManager->clear();
        $prospect = $entityManager->getRepository(Prospect::class)->findOneBy(['email' => 'alice@example.com']);
        self::assertSame('google', $prospect->getSource());
        self::assertSame('cpc', $prospect->getMedium());
        self::assertSame('lnstrade_europe', $prospect->getCampaign());

        $entityManager->getConnection()->executeStatement('UPDATE visitor_session SET last_seen_at = :expired', ['expired' => $now->modify('-31 minutes')->format('Y-m-d H:i:s')]);
        $recorder->record(Request::create('/fr/', server: $server), $now);
        $client->request('POST', '/contact', $data);
        self::assertResponseIsSuccessful();
        $entityManager->clear();
        $prospect = $entityManager->getRepository(Prospect::class)->findOneBy(['email' => 'alice@example.com']);
        self::assertSame('direct', $prospect->getSource());
        self::assertNull($prospect->getMedium());
        self::assertNull($prospect->getCampaign());
        self::assertSame(2, $prospect->getSubmissionCount());
        self::assertSame(1, $entityManager->getRepository(Prospect::class)->count([]));
    }

    public function testHoneypotSilentlyRejectsBots(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/');
        $token = $crawler->filter('#contact-form input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/contact', [
            '_csrf_token' => $token,
            'website' => 'https://spam.example',
        ], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"ok":true}', (string) $client->getResponse()->getContent());
    }
}
