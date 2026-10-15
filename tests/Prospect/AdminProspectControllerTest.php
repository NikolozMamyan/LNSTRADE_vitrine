<?php

declare(strict_types=1);

namespace App\Tests\Prospect;

use App\Entity\AdminSession;
use App\Entity\AdminUser;
use App\Entity\Prospect;
use App\HubSpot\ContactSubmission;
use App\HubSpot\HubSpotException;
use App\HubSpot\HubSpotGateway;
use App\Prospect\ProspectHubSpotSender;
use App\Security\SessionManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class AdminProspectControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Prospect $prospect;
    private ContactSubmission $submission;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $this->entityManager->getConnection()->getParams()['driver']);
        $metadata = array_map($this->entityManager->getClassMetadata(...), [Prospect::class, AdminUser::class, AdminSession::class]);
        (new SchemaTool($this->entityManager))->createSchema($metadata);
        $user = new AdminUser('prospects-test@example.com');
        $user->setPassword('unused-test-password');
        $this->submission = new ContactSubmission(
            name: 'Alice Martin',
            company: 'Example SAS',
            email: 'alice@example.com',
            phone: '+33 1 23 45 67 89',
            vat: 'FR123456',
            message: '<script>alert(1)</script> Demande de tarifs',
            context: 'Grossiste snacks et boissons',
            origin: 'https://lnstrade.fr/fr/?utm_source=google',
            locale: 'fr',
        );
        $this->prospect = new Prospect($this->submission, new \DateTimeImmutable('2026-09-01'));
        $this->entityManager->persist($user);
        $this->entityManager->persist($this->prospect);
        $this->entityManager->flush();
        $cookie = $container->get(SessionManager::class)->start($user, Request::create('https://localhost/admin'));
        $this->client->getCookieJar()->set(Cookie::fromString((string) $cookie, 'https://localhost'));
    }

    public function testItSendsTheSavedDataAndDoesNotResendASynchronizedProspect(): void
    {
        $hubSpot = $this->createMock(HubSpotGateway::class);
        $hubSpot->expects(self::once())->method('submit')->with(self::equalTo($this->submission));
        static::getContainer()->set(HubSpotGateway::class, $hubSpot);
        $token = $this->token();
        $id = $this->prospect->getId();

        $this->client->request('POST', 'https://localhost/admin/prospects/'.$id.'/hubspot', ['_token' => $token, 'page' => 1]);
        self::assertResponseRedirects('/admin/prospects?page=1', 303);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Prospect::class, $id)->getHubSpotSyncedAt());
        $this->client->followRedirect();
        self::assertSelectorExists('.admin-flash-success');
        self::assertSelectorNotExists('#prospect-'.$id.' form');
        self::assertSelectorTextContains('#prospect-'.$id, 'Synchronisé');

        $this->client->request('POST', 'https://localhost/admin/prospects/'.$id.'/hubspot', ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);
    }

    public function testAFailedSendKeepsTheProspectAndCanBeRetried(): void
    {
        $attempts = 0;
        $hubSpot = $this->createMock(HubSpotGateway::class);
        $hubSpot->expects(self::exactly(2))->method('submit')->willReturnCallback(static function () use (&$attempts): void {
            if (1 === ++$attempts) {
                throw new HubSpotException('HubSpot is unavailable.');
            }
        });
        static::getContainer()->set(HubSpotGateway::class, $hubSpot);
        $token = $this->token();
        $id = $this->prospect->getId();

        $this->client->request('POST', 'https://localhost/admin/prospects/'.$id.'/hubspot', ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        $this->entityManager->clear();
        $saved = $this->entityManager->find(Prospect::class, $id);
        self::assertNull($saved->getHubSpotSyncedAt());
        self::assertEquals($this->submission, $saved->toContactSubmission());
        $this->client->followRedirect();
        self::assertSelectorExists('.admin-flash-error');
        self::assertSelectorExists('#prospect-'.$id.' form');

        $this->client->request('POST', 'https://localhost/admin/prospects/'.$id.'/hubspot', ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Prospect::class, $id)->getHubSpotSyncedAt());
    }

    public function testTheSendRequiresAnAdminAndTheProspectsCsrfToken(): void
    {
        $hubSpot = $this->createMock(HubSpotGateway::class);
        $hubSpot->expects(self::never())->method('submit');
        static::getContainer()->set(HubSpotGateway::class, $hubSpot);
        $token = $this->token();
        $url = 'https://localhost/admin/prospects/'.$this->prospect->getId().'/hubspot';

        $this->client->request('POST', $url, ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', 'https://localhost/admin/prospects/999/hubspot', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(405);
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', $url, ['_token' => $token]);
        self::assertResponseRedirects('/admin/login');
        $this->client->request('GET', 'https://localhost/admin/prospects');
        self::assertResponseRedirects('/admin/login');
        self::assertNull($this->prospect->getHubSpotSyncedAt());
    }

    public function testItExplainsWhenHubSpotRejectsTheEmail(): void
    {
        $hubSpot = $this->createMock(HubSpotGateway::class);
        $hubSpot->expects(self::once())->method('submit')->willThrowException(new HubSpotException('Invalid email.', statusCode: 400, field: 'email'));
        static::getContainer()->set(HubSpotGateway::class, $hubSpot);
        $token = $this->token();
        $id = $this->prospect->getId();

        $this->client->request('POST', 'https://localhost/admin/prospects/'.$id.'/hubspot', ['_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-flash-error', 'HubSpot refuse l’adresse e-mail');
        self::assertSelectorExists('#prospect-'.$id.' form');
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Prospect::class, $id)->getHubSpotSyncedAt());
    }

    public function testItCanRefuseSynchronizationKeepTheProspectAndAllowItAgain(): void
    {
        $hubSpot = $this->createMock(HubSpotGateway::class);
        $hubSpot->expects(self::never())->method('submit');
        static::getContainer()->set(HubSpotGateway::class, $hubSpot);
        $sendToken = $this->token();
        $decisionToken = $this->token('hubspot/decision');
        $id = $this->prospect->getId();
        $decisionUrl = 'https://localhost/admin/prospects/'.$id.'/hubspot/decision';

        $this->client->request('POST', $decisionUrl, ['_token' => $decisionToken, 'decision' => 'reject', 'page' => 1]);
        self::assertResponseRedirects('/admin/prospects?page=1', 303);
        $this->entityManager->clear();
        $saved = $this->entityManager->find(Prospect::class, $id);
        self::assertNotNull($saved);
        self::assertNotNull($saved->getHubSpotRejectedAt());
        self::assertNull($saved->getHubSpotSyncedAt());
        self::assertEquals($this->submission, $saved->toContactSubmission());
        self::assertSame(1, $this->entityManager->getRepository(Prospect::class)->count([]));
        static::getContainer()->get(ProspectHubSpotSender::class)->send($saved);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#prospect-'.$id, 'Synchronisation refusée');
        self::assertSelectorNotExists('#prospect-'.$id.' form[action="/admin/prospects/'.$id.'/hubspot"]');
        self::assertSelectorExists('#prospect-'.$id.' input[name="decision"][value="allow"]');

        $this->client->request('POST', 'https://localhost/admin/prospects/'.$id.'/hubspot', ['_token' => $sendToken]);
        self::assertResponseRedirects('/admin/prospects?page=1', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-flash-error', 'La synchronisation est refusée');

        $this->client->request('POST', $decisionUrl, ['_token' => $decisionToken, 'decision' => 'allow']);
        self::assertResponseRedirects('/admin/prospects?page=1', 303);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Prospect::class, $id)->getHubSpotRejectedAt());
        $this->client->followRedirect();
        self::assertSelectorExists('#prospect-'.$id.' form[action="/admin/prospects/'.$id.'/hubspot"]');
        self::assertSelectorExists('#prospect-'.$id.' input[name="decision"][value="reject"]');
    }

    public function testTheSynchronizationDecisionRequiresAnAdminCsrfAndAnUnsynchronizedProspect(): void
    {
        $hubSpot = $this->createMock(HubSpotGateway::class);
        $hubSpot->expects(self::never())->method('submit');
        static::getContainer()->set(HubSpotGateway::class, $hubSpot);
        $token = $this->token('hubspot/decision');
        $url = 'https://localhost/admin/prospects/'.$this->prospect->getId().'/hubspot/decision';

        $this->client->request('POST', $url, ['_token' => 'invalid', 'decision' => 'reject']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url, ['_token' => $token, 'decision' => 'invalid']);
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(405);

        $prospect = $this->entityManager->find(Prospect::class, $this->prospect->getId());
        $prospect->markHubSpotSynced(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->client->request('POST', $url, ['_token' => $token, 'decision' => 'reject']);
        self::assertResponseStatusCodeSame(409);
        self::assertNull($prospect->getHubSpotRejectedAt());

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', $url, ['_token' => $token, 'decision' => 'reject']);
        self::assertResponseRedirects('/admin/login');
    }

    public function testOlderProspectsRemainAccessibleAndMessagesAreEscaped(): void
    {
        for ($number = 0; $number < 26; ++$number) {
            $this->entityManager->persist(new Prospect(new ContactSubmission(
                name: 'New contact '.$number,
                company: 'Example SAS',
                email: 'contact'.$number.'@example.com',
                phone: '',
                vat: '',
                message: '',
                context: '',
                origin: '',
                locale: 'en',
            ), new \DateTimeImmutable('2026-10-01')));
        }
        $this->entityManager->flush();
        $this->client->request('GET', 'https://localhost/admin/prospects');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(25, '.admin-table tbody tr');
        self::assertSelectorNotExists('#prospect-'.$this->prospect->getId());
        self::assertSelectorExists('a[href="/admin/prospects?page=2"]');

        $this->client->request('GET', 'https://localhost/admin/prospects?page=999');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.admin-table tbody tr');
        self::assertSelectorExists('#prospect-'.$this->prospect->getId().' form');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', (string) $this->client->getResponse()->getContent());
    }

    private function token(string $action = 'hubspot'): string
    {
        $crawler = $this->client->request('GET', 'https://localhost/admin/prospects');
        self::assertResponseIsSuccessful();

        return $crawler->filter('#prospect-'.$this->prospect->getId().' form[action="/admin/prospects/'.$this->prospect->getId().'/'.$action.'"] input[name="_token"]')->attr('value');
    }
}
