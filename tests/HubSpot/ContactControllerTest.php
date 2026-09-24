<?php

declare(strict_types=1);

namespace App\Tests\HubSpot;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ContactControllerTest extends WebTestCase
{
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
