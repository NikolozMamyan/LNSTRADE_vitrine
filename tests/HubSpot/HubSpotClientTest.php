<?php

declare(strict_types=1);

namespace App\Tests\HubSpot;

use App\HubSpot\ContactSubmission;
use App\HubSpot\HubSpotClient;
use App\HubSpot\HubSpotException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HubSpotClientTest extends TestCase
{
    public function testItCreatesAndAssociatesACompanyAndContactWithANote(): void
    {
        $requests = [];
        $responses = [
            new MockResponse('{"results":[]}', ['http_code' => 200]),
            new MockResponse('{"id":"company-1"}', ['http_code' => 201]),
            new MockResponse('{"status":"error"}', ['http_code' => 404]),
            new MockResponse('{"id":"contact-1"}', ['http_code' => 201]),
            new MockResponse('{}', ['http_code' => 201]),
            new MockResponse('{"id":"note-1"}', ['http_code' => 201]),
        ];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = [$method, $url, $options];

            return array_shift($responses);
        });

        (new HubSpotClient($httpClient, 'secret-token', '65157022'))->submit($this->submission());

        self::assertCount(6, $requests);
        self::assertSame('https://api.hubapi.com/crm/objects/2026-03/companies/search', $requests[0][1]);
        self::assertSame('https://api.hubapi.com/crm/objects/2026-03/companies', $requests[1][1]);
        self::assertStringContainsString('/contacts/alice%40example.com', $requests[2][1]);
        self::assertSame('https://api.hubapi.com/crm/objects/2026-03/contacts', $requests[3][1]);
        $updatedContact = json_decode($requests[2][2]['body'], true, flags: JSON_THROW_ON_ERROR);
        $createdContact = json_decode($requests[3][2]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('65157022', $updatedContact['properties']['hubspot_owner_id']);
        self::assertSame('65157022', $createdContact['properties']['hubspot_owner_id']);
        self::assertStringContainsString('/contacts/contact-1/associations/companies/company-1', $requests[4][1]);
        self::assertSame('https://api.hubapi.com/crm/objects/2026-03/notes', $requests[5][1]);

        $note = json_decode($requests[5][2]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(202, $note['associations'][0]['types'][0]['associationTypeId']);
        self::assertStringContainsString('Demande de catalogue', $note['properties']['hs_note_body']);
        self::assertStringContainsString('FR123456', $note['properties']['hs_note_body']);
    }

    public function testItIdentifiesAnEmailRejectedByHubSpot(): void
    {
        $responses = [
            new MockResponse('{"results":[{"id":"company-1"}]}', ['http_code' => 200]),
            new MockResponse('{"status":"error"}', ['http_code' => 404]),
            new MockResponse('{"status":"error","message":"INVALID_EMAIL for email"}', ['http_code' => 400]),
        ];
        $httpClient = new MockHttpClient(static function () use (&$responses): MockResponse {
            return array_shift($responses);
        });

        try {
            (new HubSpotClient($httpClient, 'secret-token', '65157022'))->submit($this->submission());
            self::fail('A HubSpotException should have been thrown.');
        } catch (HubSpotException $exception) {
            self::assertSame(400, $exception->statusCode, $exception->getMessage());
            self::assertSame('email', $exception->field);
        }
    }

    private function submission(): ContactSubmission
    {
        return new ContactSubmission(
            name: 'Alice Martin',
            company: 'Example SAS',
            email: 'alice@example.com',
            phone: '+33 1 23 45 67 89',
            vat: 'FR123456',
            message: 'Bonjour, je souhaite recevoir votre catalogue.',
            context: 'Demande de catalogue',
            origin: 'https://lnstrade.fr/fr/',
            locale: 'fr',
        );
    }
}
