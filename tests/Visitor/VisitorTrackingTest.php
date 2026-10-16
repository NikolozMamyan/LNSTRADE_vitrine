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
use App\Visitor\IpGeolocation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

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
        self::assertSelectorExists('a[data-turbo="false"][href^="/admin/visiteurs/export.xlsx?days=30"]');
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
        foreach (['/admin/visiteurs', '/admin/visiteurs/1', '/admin/visiteurs/export.xlsx'] as $url) {
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

    public function testTrafficSharesCountOnlyPagesInTheSelectedPeriodAndIp(): void
    {
        $recorder = static::getContainer()->get(VisitorRecorder::class);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $start = $today->modify('-6 days');
        $request = Request::create('/fr/?utm_source=google&utm_medium=cpc&utm_campaign=lnstrade_europe', server: [
            'REMOTE_ADDR' => '198.51.100.20', 'HTTP_USER_AGENT' => 'Chrome/140.0',
        ]);
        $recorder->record($request, $start->modify('-5 minutes'));
        $recorder->record($request, $start->modify('+5 minutes'));
        $recorder->record($request, $start->modify('+10 minutes'));
        $request = Request::create('/en/?utm_source=google&utm_medium=cpc&utm_campaign=lnstrade_hors_europe', server: [
            'REMOTE_ADDR' => '198.51.100.21', 'HTTP_USER_AGENT' => 'Chrome/140.0',
        ]);
        $recorder->record($request, $today->modify('+10 minutes'));
        $request = Request::create('/fr/', server: ['REMOTE_ADDR' => '198.51.100.22', 'HTTP_USER_AGENT' => 'Chrome/140.0']);
        foreach ([15, 20, 25] as $minutes) {
            $recorder->record($request, $today->modify('+'.$minutes.' minutes'));
        }

        $reports = static::getContainer()->get(VisitorReportProvider::class);
        $report = $reports->overview(Request::create('/admin/visiteurs?days=7'));
        self::assertSame(6, $report['periodViews']);
        self::assertSame(3, $report['campaignViews']);
        self::assertEqualsWithDelta(50.0, $report['campaignShare'], 0.001);
        self::assertEqualsWithDelta(6 / 7, $report['averageDailyViews'], 0.001);
        self::assertSame(4, $report['peakDay']['views']);
        $sources = array_column($report['trafficSources'], null, 'source');
        self::assertSame(3, $sources['google']['views']);
        self::assertEqualsWithDelta(50.0, $sources['google']['share'], 0.001);
        self::assertSame(3, $sources['direct']['views']);
        $campaigns = array_column($report['trafficCampaigns'], null, 'campaign');
        self::assertSame(2, $campaigns['lnstrade_europe']['views']);
        self::assertEqualsWithDelta(100 / 3, $campaigns['lnstrade_europe']['share'], 0.001);
        self::assertSame(1, $campaigns['lnstrade_hors_europe']['views']);
        self::assertSame(3, $campaigns['']['views']);
        self::assertEqualsWithDelta(100.0, array_sum(array_column($report['trafficCampaigns'], 'share')), 0.001);
        self::assertSame(2, $report['chart'][0]['views']);
        self::assertEqualsWithDelta(100.0, $report['chart'][0]['campaignShare'], 0.001);
        self::assertEqualsWithDelta(25.0, $report['chart'][6]['campaignShare'], 0.001);
        self::assertSame('direct', $report['chart'][6]['topSource']['source']);

        $filtered = $reports->overview(Request::create('/admin/visiteurs?days=7&ip=198.51.100.20'));
        self::assertSame(2, $filtered['periodViews']);
        self::assertCount(1, $filtered['trafficSources']);
        self::assertCount(1, $filtered['trafficCampaigns']);
        self::assertEqualsWithDelta(100.0, $filtered['trafficCampaigns'][0]['share'], 0.001);
        $empty = $reports->overview(Request::create('/admin/visiteurs?days=7&ip=198.51.100.99'));
        self::assertSame(0, $empty['periodViews']);
        self::assertSame(0, $empty['campaignShare']);
        self::assertSame([], $empty['trafficSources']);
        self::assertSame([], $empty['trafficCampaigns']);
        self::assertNull($empty['peakDay']);
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

    public function testExcelExportContainsFilteredNumbersChartsAndAllSourcesWithoutExecutingVisitorText(): void
    {
        $http = new MockHttpClient();
        static::getContainer()->set(IpGeolocation::class, new IpGeolocation($http, new ArrayAdapter(), new RateLimiterFactory([
            'id' => 'export_geolocation', 'policy' => 'fixed_window', 'limit' => 40, 'interval' => '1 minute',
        ], new InMemoryStorage()), null));
        $recorder = static::getContainer()->get(VisitorRecorder::class);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        for ($number = 1; $number <= 26; ++$number) {
            $query = http_build_query([
                'utm_source' => 26 === $number ? '=1+1' : 'source_'.$number,
                'utm_medium' => 'cpc',
                'utm_campaign' => 0 === $number % 2 ? '=2+2' : '',
            ]);
            $recorder->record(Request::create('/fr/?'.$query, server: [
                'REMOTE_ADDR' => '8.8.8.8', 'HTTP_USER_AGENT' => 'Chrome/140.0-'.$number,
            ]), $today->modify('+15 minutes'));
        }
        $recorder->record(Request::create('/fr/?utm_source=outside_period', server: [
            'REMOTE_ADDR' => '8.8.8.8', 'HTTP_USER_AGENT' => 'Chrome/140.0',
        ]), $today->modify('-8 days'));
        $recorder->record(Request::create('/fr/?utm_source=other_ip', server: [
            'REMOTE_ADDR' => '198.51.100.21', 'HTTP_USER_AGENT' => 'Chrome/140.0',
        ]), $today);

        $this->login();
        $this->client->request('GET', 'https://localhost/admin/visiteurs/export.xlsx?days=7&ip=8.8.8.8&page=2');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('attachment; filename=lnstrade-visiteurs-', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(0, $http->getRequestsCount());
        self::assertSame(28, $this->entityManager->getRepository(PageVisit::class)->count([]));

        $book = $this->readExcelExport();
        try {
            self::assertSame(['Synthèse', 'Évolution', 'Sources', 'Campagnes'], $book->getSheetNames());
            $summary = $book->getSheetByName('Synthèse');
            self::assertSame(7, $summary->getCell('B9')->getValue());
            self::assertSame('8.8.8.8', $summary->getCell('B10')->getValue());
            self::assertSame(26, $summary->getCell('B12')->getValue());
            self::assertSame(26, $summary->getCell('B13')->getValue());
            self::assertSame(13, $summary->getCell('B17')->getValue());
            self::assertEqualsWithDelta(0.5, $summary->getCell('B18')->getValue(), 0.001);
            self::assertSame('0.0%', $summary->getCell('B18')->getStyle()->getNumberFormat()->getFormatCode());
            $daily = $book->getSheetByName('Évolution');
            self::assertSame(26, $daily->getCell('B13')->getValue());
            self::assertSame(0, $daily->getCell('B7')->getValue());
            self::assertSame(2, $daily->getChartCount());
            self::assertSame(DataSeriesValues::DATASERIES_TYPE_NUMBER, $daily->getChartByIndex(0)->getPlotArea()->getPlotGroupByIndex(0)->getPlotCategoryByIndex(0)->getDataType());
            $campaignSeries = $daily->getChartByIndex(1)->getPlotArea()->getPlotGroupByIndex(0);
            self::assertSame(DataSeries::GROUPING_STANDARD, $campaignSeries->getPlotGrouping());
            self::assertEqualsWithDelta(0.5, $campaignSeries->getPlotValuesByIndex(0)->getDataValues()[6], 0.001);
            $sources = $book->getSheetByName('Sources');
            self::assertSame(32, $sources->getHighestDataRow());
            self::assertSame('=1+1', $sources->getCell('A7')->getValue());
            self::assertSame(DataType::TYPE_STRING, $sources->getCell('A7')->getDataType());
            self::assertSame(1, $sources->getChartCount());
            self::assertSame(10, $sources->getChartByIndex(0)->getPlotArea()->getPlotGroupByIndex(0)->getPlotValuesByIndex(0)->getPointCount());
            $campaigns = $book->getSheetByName('Campagnes');
            self::assertSame('=2+2', $campaigns->getCell('A8')->getValue());
            self::assertSame(DataType::TYPE_STRING, $campaigns->getCell('A8')->getDataType());
            self::assertSame(20, $campaigns->getHighestDataRow());
            self::assertSame(1, $campaigns->getChartCount());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function testExcelExportHandlesEmptyInvalidIpAndAllHistoryLikeTheChart(): void
    {
        $this->login();
        $this->client->request('GET', 'https://localhost/admin/visiteurs/export.xlsx?days=0&ip=invalid');
        self::assertResponseIsSuccessful();
        $book = $this->readExcelExport();
        try {
            $summary = $book->getSheetByName('Synthèse');
            self::assertSame(30, $summary->getCell('B9')->getValue());
            self::assertStringContainsString('IP invalide', $summary->getCell('B10')->getValue());
            self::assertSame(0, $summary->getCell('B12')->getValue());
            self::assertEqualsWithDelta(0, $summary->getCell('B18')->getValue(), 0.001);
            self::assertStringContainsString('30 derniers jours du graphique', $summary->getCell('A31')->getValue());
            self::assertSame(36, $book->getSheetByName('Évolution')->getHighestDataRow());
            self::assertSame(2, $book->getSheetByName('Évolution')->getChartCount());
            foreach (['Sources', 'Campagnes'] as $name) {
                self::assertSame('Aucune page consultée sur cette période.', $book->getSheetByName($name)->getCell('A8')->getValue());
                self::assertSame(0, $book->getSheetByName($name)->getChartCount());
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function readExcelExport(): Spreadsheet
    {
        $filename = tempnam(sys_get_temp_dir(), 'lnstrade_visitors_');
        try {
            file_put_contents($filename, $this->client->getInternalResponse()->getContent());
            $reader = new Xlsx();
            $reader->setIncludeCharts(true);

            return $reader->load($filename);
        } finally {
            unlink($filename);
        }
    }

    public function testIpLocationAppearsInTheListAndSessionDetails(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'status' => 'success', 'query' => '212.32.69.206', 'country' => 'États-Unis',
            'countryCode' => 'US', 'city' => 'Dallas', 'lat' => 0, 'lon' => 0,
            'isp' => '<script>untrusted</script>',
        ], JSON_THROW_ON_ERROR)));
        static::getContainer()->set(IpGeolocation::class, new IpGeolocation($http, new ArrayAdapter(), new RateLimiterFactory([
            'id' => 'geolocation', 'policy' => 'fixed_window', 'limit' => 40, 'interval' => '1 minute',
        ], new InMemoryStorage()), null));
        $this->client->setServerParameter('REMOTE_ADDR', '212.32.69.206');
        $this->client->request('GET', '/fr/');
        $this->login();
        $this->client->request('GET', 'https://localhost/admin/visiteurs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.visitor-panel tbody tr td:nth-child(2)', 'États-Unis (US)');
        self::assertSelectorTextContains('.visitor-panel tbody tr td:nth-child(2)', 'Dallas');
        $id = $this->entityManager->getRepository(VisitorSession::class)->findOneBy([])->getId();
        $this->client->request('GET', 'https://localhost/admin/visiteurs/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.visitor-session-summary', 'Latitude approximative');
        self::assertStringContainsString('&lt;script&gt;untrusted&lt;/script&gt;', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $http->getRequestsCount());
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
