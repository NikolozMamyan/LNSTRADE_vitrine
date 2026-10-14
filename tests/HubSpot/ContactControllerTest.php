<?php

declare(strict_types=1);

namespace App\Tests\HubSpot;

use App\Entity\Prospect;
use App\HubSpot\HubSpotException;
use App\HubSpot\HubSpotGateway;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ContactControllerTest extends WebTestCase
{
    #[DataProvider('hubSpotResults')]
    public function testItConfirmsSubmissionOnlyAfterHubSpotAcceptsIt(bool $hubSpotFails): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $entityManager->getConnection()->getParams()['driver']);
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(Prospect::class)]);

        $hubSpot = $this->createMock(HubSpotGateway::class);
        $submission = $hubSpot->expects(self::once())->method('submit');
        if ($hubSpotFails) {
            $submission->willThrowException(new HubSpotException('HubSpot is unavailable.'));
        }
        $container->set(HubSpotGateway::class, $hubSpot);

        $crawler = $client->request('GET', '/fr/');
        $client->request('POST', '/contact', [
            '_csrf_token' => $crawler->filter('#contact-form input[name="_csrf_token"]')->attr('value'),
            'name' => 'Alice Martin',
            'company' => 'Example SAS',
            'email' => 'alice@example.com',
            'locale' => 'fr',
        ], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame($hubSpotFails ? Response::HTTP_BAD_GATEWAY : Response::HTTP_OK);
        self::assertJsonStringEqualsJsonString(
            $hubSpotFails ? '{"ok":false,"error":"hubspot"}' : '{"ok":true,"submitted":true}',
            (string) $client->getResponse()->getContent(),
        );
        $prospect = $entityManager->getRepository(Prospect::class)->findOneBy(['email' => 'alice@example.com']);
        self::assertInstanceOf(Prospect::class, $prospect);
        self::assertSame(!$hubSpotFails, null !== $prospect->getHubSpotSyncedAt());
    }

    public static function hubSpotResults(): iterable
    {
        yield 'accepted submission' => [false];
        yield 'HubSpot failure' => [true];
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
