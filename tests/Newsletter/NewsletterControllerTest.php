<?php

declare(strict_types=1);

namespace App\Tests\Newsletter;

use App\Entity\AdminSession;
use App\Entity\AdminUser;
use App\Entity\NewsletterSubscriber;
use App\Entity\Prospect;
use App\HubSpot\HubSpotGateway;
use App\Security\SessionManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class NewsletterControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->entityManager->getConnection()->getParams()['driver']);
        (new SchemaTool($this->entityManager))->createSchema(array_map($this->entityManager->getClassMetadata(...), [NewsletterSubscriber::class, Prospect::class, AdminUser::class, AdminSession::class]));
        static::getContainer()->set('limiter.newsletter', new RateLimiterFactory([
            'id' => 'newsletter_test', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes',
        ], new InMemoryStorage()));
        $gateway = $this->createMock(HubSpotGateway::class);
        $gateway->expects(self::never())->method('submit');
        static::getContainer()->set(HubSpotGateway::class, $gateway);
        $crawler = $this->client->request('GET', 'https://localhost/fr/');
        $this->token = $crawler->filter('#newsletter-form input[name="_csrf_token"]')->attr('value');
    }

    public function testItStoresExplicitSubscriptionsSeparatelyAndDeduplicatesEmails(): void
    {
        $this->subscribe(['email' => ' Alice@Example.com ', 'origin' => 'https://lnstrade.fr/fr/?email=private@example.com#section']);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"ok":true,"subscribed":true,"created":true}', $this->client->getResponse()->getContent());
        $this->subscribe(['email' => 'alice@example.com']);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"ok":true,"subscribed":true,"created":false}', $this->client->getResponse()->getContent());
        $this->entityManager->clear();
        $repository = $this->entityManager->getRepository(NewsletterSubscriber::class);
        self::assertSame(1, $repository->count([]));
        $subscriber = $repository->findOneBy([]);
        self::assertSame('alice@example.com', $subscriber->getEmail());
        self::assertSame('fr', $subscriber->getLocale());
        self::assertSame('/fr/', $subscriber->getOriginPath());
        self::assertSame(NewsletterSubscriber::CONSENT_VERSION, $subscriber->getConsentVersion());
        self::assertInstanceOf(\DateTimeImmutable::class, $subscriber->getSubscribedAt());
        self::assertSame(0, $this->entityManager->getRepository(Prospect::class)->count([]));
    }

    public function testInvalidEmailMissingConsentAndForeignOriginCannotCreateSubscribers(): void
    {
        foreach ([['email' => 'invalid'], ['email' => ''], ['consent' => ''], ['consent' => '0']] as $data) {
            $this->subscribe($data);
            self::assertResponseStatusCodeSame(422);
        }
        $this->subscribe([], ['HTTP_ORIGIN' => 'https://attacker.example', 'HTTP_REFERER' => 'https://attacker.example/form']);
        self::assertResponseStatusCodeSame(403);
        $this->subscribe(['_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->entityManager->getRepository(NewsletterSubscriber::class)->count([]));
    }

    public function testBotsAndRepeatedRequestsAreBlockedWithoutSavingSalesProspects(): void
    {
        $this->subscribe(['website' => 'https://spam.example']);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"ok":true,"subscribed":false}', $this->client->getResponse()->getContent());
        for ($index = 0; $index < 5; ++$index) {
            $this->subscribe(['email' => 'person'.$index.'@example.com']);
            self::assertResponseIsSuccessful();
        }
        $this->subscribe(['email' => 'limited@example.com']);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(5, $this->entityManager->getRepository(NewsletterSubscriber::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(Prospect::class)->count([]));
    }

    public function testOnlyEligiblePagesHaveTheInvitationAndEnglishCopyIsTranslated(): void
    {
        foreach (['/en/', '/fr/blog', '/fr/blog/choisir-grossiste-boissons-manga'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-newsletter][hidden]');
        }
        $this->client->request('GET', '/en/');
        self::assertSelectorTextContains('#newsletter-title', 'Something new');
        foreach (['/fr/grossiste-snacks-boissons-manga', '/en/manga-snacks-drinks-wholesaler', '/fr/politique-de-confidentialite', '/fr/conditions-generales-utilisation'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('[data-newsletter]');
        }
    }

    public function testTheAdminCanMonitorSubscriptionsButAnonymousUsersCannot(): void
    {
        $this->subscribe();
        $this->client->request('GET', 'https://localhost/admin/newsletter');
        self::assertResponseRedirects('/admin/login');
        $user = new AdminUser('newsletter-test@example.com');
        $user->setPassword('unused');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $cookie = static::getContainer()->get(SessionManager::class)->start($user, Request::create('https://localhost/admin'));
        $this->client->getCookieJar()->set(Cookie::fromString((string) $cookie, 'https://localhost'));
        $this->client->request('GET', 'https://localhost/admin/newsletter');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.admin-nav-link.is-active[href="/admin/newsletter"]');
        self::assertSelectorTextContains('tbody', 'alice@example.com');
        self::assertSelectorTextContains('.metric-card-dark .metric-value', '1');
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
    }

    private function subscribe(array $data = [], array $server = []): void
    {
        $this->client->request('POST', 'https://localhost/newsletter/subscribe', array_replace([
            '_csrf_token' => $this->token, 'email' => 'alice@example.com', 'locale' => 'fr', 'origin' => '/fr/', 'consent' => '1',
        ], $data), server: array_replace(['HTTP_ORIGIN' => 'https://localhost', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '198.51.100.92'], $server));
    }
}
