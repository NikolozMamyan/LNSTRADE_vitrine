<?php

declare(strict_types=1);

namespace App\Tests\Visitor;

use App\Entity\AdminSession;
use App\Entity\AdminUser;
use App\Entity\PageVisit;
use App\Entity\VisitorSession;
use App\Security\SessionManager;
use App\Visitor\VisitorRecorder;
use App\Visitor\VisitorReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class VisitorTrackingTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $_ENV['VISITOR_TRACKING_ENABLED'] = $_SERVER['VISITOR_TRACKING_ENABLED'] = '1';
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->entityManager->getConnection()->getParams()['driver']);
        $metadata = array_map($this->entityManager->getClassMetadata(...), [VisitorSession::class, PageVisit::class, AdminUser::class, AdminSession::class]);
        (new SchemaTool($this->entityManager))->createSchema($metadata);
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.20');
        $this->client->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 Chrome/140.0');
    }

    protected function tearDown(): void
    {
        $_ENV['VISITOR_TRACKING_ENABLED'] = $_SERVER['VISITOR_TRACKING_ENABLED'] = '0';
        parent::tearDown();
    }

    public function testItRecordsTheNavigationAndCampaignWithoutSavingOtherQueryParameters(): void
    {
        $this->client->request('GET', '/fr/?utm_source=google&utm_medium=cpc&utm_campaign=lnstrade_europe&email=private@example.com');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/en/manga-snacks-drinks-wholesaler');
        self::assertResponseIsSuccessful();
        $session = $this->entityManager->getRepository(VisitorSession::class)->findOneBy([]);
        self::assertInstanceOf(VisitorSession::class, $session);
        self::assertSame('198.51.100.20', $session->getIpAddress());
        self::assertSame('/fr/', $session->getEntryPath());
        self::assertSame('/en/manga-snacks-drinks-wholesaler', $session->getCurrentPath());
        self::assertSame(2, $session->getPageViewCount());
        self::assertSame('google', $session->getSource());
        self::assertSame('cpc', $session->getMedium());
        self::assertSame('lnstrade_europe', $session->getCampaign());
        self::assertSame(1, $this->entityManager->getRepository(VisitorSession::class)->count([]));
        self::assertSame(2, $this->entityManager->getRepository(PageVisit::class)->count([]));
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testPrefetchRobotsRedirectsErrorsAndAdminRequestsAreExcluded(): void
    {
        foreach (['HTTP_X_SEC_PURPOSE' => 'prefetch', 'HTTP_SEC_PURPOSE' => 'prefetch', 'HTTP_X_LNS_PREFETCH' => '1', 'HTTP_TURBO_FRAME' => 'frame'] as $header => $value) {
            $this->client->request('GET', '/fr/', server: [$header => $value]);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('GET', '/fr/', server: ['HTTP_USER_AGENT' => 'Googlebot/2.1']);
        $this->client->request('GET', '/');
        $this->client->request('GET', '/missing-page');
        $this->client->request('GET', '/admin/login');
        self::assertSame(0, $this->entityManager->getRepository(VisitorSession::class)->count([]));
    }

    public function testThirtyMinutesOfInactivityStartsANewSessionAndDifferentBrowsersStaySeparate(): void
    {
        $recorder = static::getContainer()->get(VisitorRecorder::class);
        $request = Request::create('/fr/', server: ['REMOTE_ADDR' => '2001:db8::1', 'HTTP_USER_AGENT' => 'Firefox/140.0', 'HTTP_X_FORWARDED_FOR' => '198.51.100.99']);
        $now = new \DateTimeImmutable('2026-10-14 10:00:00 UTC');
        $recorder->record($request, $now);
        $recorder->record($request, $now->modify('+29 minutes'));
        $recorder->record($request, $now->modify('+59 minutes'));
        $request->headers->set('User-Agent', 'Chrome/140.0');
        $recorder->record($request, $now->modify('+60 minutes'));
        $sessions = $this->entityManager->getRepository(VisitorSession::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(3, $sessions);
        self::assertSame(2, $sessions[0]->getPageViewCount());
        self::assertSame('2001:db8::1', $sessions[0]->getIpAddress());
        self::assertSame($sessions[0]->getVisitorKey(), $sessions[1]->getVisitorKey());
        self::assertNotSame($sessions[0]->getVisitorKey(), $sessions[2]->getVisitorKey());
    }

    public function testTheAdminSeesAnEscapedHistoryAndLiveFragmentsWithoutCountingItsOwnRequests(): void
    {
        $this->client->request('GET', '/fr/?utm_campaign='.urlencode('<script>alert(1)</script>'));
        $this->client->request('GET', '/fr/grossiste-snacks-boissons-manga');
        $this->login();
        $this->client->request('GET', 'https://localhost/admin/visiteurs');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.admin-nav-link.is-active[href="/admin/visiteurs"]');
        self::assertSelectorCount(1, '.visitor-panel .admin-table tbody tr');
        self::assertSelectorTextContains('.visitor-ip', '198.51.100.20');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $id = $this->entityManager->getRepository(VisitorSession::class)->findOneBy([])->getId();
        $this->client->request('GET', 'https://localhost/admin/visiteurs/'.$id, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.admin-sidebar');
        self::assertSelectorCount(2, 'tbody tr');
        self::assertSelectorTextContains('tbody tr:first-child', '/fr/');
        self::assertSelectorTextContains('tbody tr:last-child', '/fr/grossiste-snacks-boissons-manga');
        self::assertSame(2, $this->entityManager->getRepository(PageVisit::class)->count([]));
    }

    public function testVisitorDataIsNotAvailableWithoutAnAdminSession(): void
    {
        foreach (['/admin/visiteurs', '/admin/visiteurs/1'] as $url) {
            $this->client->request('GET', $url, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            self::assertResponseRedirects('/admin/login');
        }
        $this->login();
        $this->client->request('GET', 'https://localhost/admin/visiteurs/999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testStatisticsUseParisDatesAndKeepIpFiltersAndOldHistoryAccessible(): void
    {
        $recorder = static::getContainer()->get(VisitorRecorder::class);
        $request = Request::create('/fr/', server: ['REMOTE_ADDR' => '198.51.100.20', 'HTTP_USER_AGENT' => 'Chrome/140.0']);
        $midnight = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $recorder->record($request, $midnight->modify('+15 minutes'));
        $recorder->record($request, $midnight->modify('-40 days'));
        $request->server->set('REMOTE_ADDR', '198.51.100.21');
        $recorder->record($request, $midnight->modify('+30 minutes'));
        $reports = static::getContainer()->get(VisitorReportProvider::class);
        $report = $reports->overview(Request::create('/admin/visiteurs?days=7&ip=198.51.100.20'));
        self::assertSame(1, $report['todayViews']);
        self::assertSame(1, $report['periodSessions']);
        self::assertSame(1, $report['sessionCount']);
        self::assertCount(7, $report['chart']);
        $all = $reports->overview(Request::create('/admin/visiteurs?days=0&ip=198.51.100.20'));
        self::assertSame(2, $all['sessionCount']);
        self::assertSame(1, $all['periodViews']);
        self::assertCount(30, $all['chart']);
        $invalid = $reports->overview(Request::create('/admin/visiteurs?ip=invalid&days=-99'));
        self::assertTrue($invalid['invalidIp']);
        self::assertSame(0, $invalid['sessionCount']);
        self::assertSame(30, $invalid['chartDays']);
    }

    public function testSessionAndPageHistoryArePaginated(): void
    {
        $recorder = static::getContainer()->get(VisitorRecorder::class);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        for ($number = 1; $number <= 26; ++$number) {
            $recorder->record(Request::create('/fr/', server: ['REMOTE_ADDR' => '198.51.100.'.$number, 'HTTP_USER_AGENT' => 'Chrome/140.0']), $now);
        }
        $reports = static::getContainer()->get(VisitorReportProvider::class);
        $overview = $reports->overview(Request::create('/admin/visiteurs?page=999'));
        self::assertSame(2, $overview['page']);
        self::assertCount(1, $overview['sessions']);
        $session = $overview['sessions'][0];
        $request = Request::create('/fr/blog', server: ['REMOTE_ADDR' => $session->getIpAddress(), 'HTTP_USER_AGENT' => 'Chrome/140.0']);
        for ($number = 1; $number <= 50; ++$number) {
            $recorder->record($request, $now->modify('+'.$number.' seconds'));
        }
        $history = $reports->history($session, Request::create('/admin/visiteurs/'.$session->getId().'?page=999'));
        self::assertSame(2, $history['page']);
        self::assertCount(1, $history['visits']);
        self::assertSame('/fr/blog', $history['visits'][0]->getPath());
    }

    private function login(): void
    {
        $user = new AdminUser('visitors-test@example.com');
        $user->setPassword('unused-test-password');
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $cookie = static::getContainer()->get(SessionManager::class)->start($user, Request::create('https://localhost/admin'));
        $this->client->getCookieJar()->set(Cookie::fromString((string) $cookie, 'https://localhost'));
    }
}
