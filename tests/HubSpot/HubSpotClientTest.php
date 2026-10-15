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
    public function testItCreatesAndAssociatesACompanyAndContactWithANoteAndDeal(): void
    {
        $requests = [];
        $responses = [
            new MockResponse('{"results":[]}', ['http_code' => 200]),
            new MockResponse('{"id":"company-1"}', ['http_code' => 201]),
            new MockResponse('{"status":"error"}', ['http_code' => 404]),
            new MockResponse('{"id":"contact-1"}', ['http_code' => 201]),
            new MockResponse('{}', ['http_code' => 201]),
            new MockResponse('{"id":"note-1"}', ['http_code' => 201]),
            new MockResponse('{"id":"deal-1"}', ['http_code' => 201]),
        ];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = [$method, $url, $options];

            return array_shift($responses);
        });

        (new HubSpotClient($httpClient, 'secret-token', '1351597969', 'default', 'appointmentscheduled'))->submit($this->submission('US'));

        self::assertCount(7, $requests);
        foreach ($requests as $request) {
            self::assertSame(5.0, $request[2]['timeout']);
            self::assertSame(10.0, $request[2]['max_duration']);
        }
        self::assertSame('https://api.hubapi.com/crm/objects/2026-03/companies/search', $requests[0][1]);
        self::assertSame('https://api.hubapi.com/crm/objects/2026-03/companies', $requests[1][1]);
        self::assertStringContainsString('/contacts/alice%40example.com', $requests[2][1]);
        self::assertSame('https://api.hubapi.com/crm/objects/2026-03/contacts', $requests[3][1]);
        $createdCompany = json_decode($requests[1][2]['body'], true, flags: JSON_THROW_ON_ERROR);
        $updatedContact = json_decode($requests[2][2]['body'], true, flags: JSON_THROW_ON_ERROR);
        $createdContact = json_decode($requests[3][2]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('1351597969', $createdCompany['properties']['hubspot_owner_id']);
        self::assertSame('United States', $createdCompany['properties']['company_country_en']);
        self::assertSame('1351597969', $updatedContact['properties']['hubspot_owner_id']);
        self::assertSame('United States', $updatedContact['properties']['nom_pays_en']);
        self::assertSame('1351597969', $createdContact['properties']['hubspot_owner_id']);
        self::assertSame('United States', $createdContact['properties']['nom_pays_en']);
        self::assertStringContainsString('/contacts/contact-1/associations/companies/company-1', $requests[4][1]);
        self::assertSame('https://api.hubapi.com/crm/objects/2026-03/notes', $requests[5][1]);

        $note = json_decode($requests[5][2]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $note['associations']);
        self::assertSame('contact-1', $note['associations'][0]['to']['id']);
        self::assertSame(202, $note['associations'][0]['types'][0]['associationTypeId']);
        self::assertSame('company-1', $note['associations'][1]['to']['id']);
        self::assertSame(190, $note['associations'][1]['types'][0]['associationTypeId']);
        self::assertStringContainsString('Demande de catalogue', $note['properties']['hs_note_body']);
        self::assertStringContainsString('FR123456', $note['properties']['hs_note_body']);
        self::assertStringContainsString('United States', $note['properties']['hs_note_body']);
        self::assertStringContainsString('Bonjour, je souhaite recevoir votre catalogue.', $note['properties']['hs_note_body']);

        self::assertSame('POST', $requests[6][0]);
        self::assertSame('https://api.hubapi.com/crm/objects/2026-03/deals', $requests[6][1]);
        $deal = json_decode($requests[6][2]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            'dealname' => 'LNSTRADE - Example SAS',
            'description' => 'Bonjour, je souhaite recevoir votre catalogue.',
            'pipeline' => 'default',
            'dealstage' => 'appointmentscheduled',
            'hubspot_owner_id' => '1351597969',
        ], $deal['properties']);
        self::assertSame([
            [
                'to' => ['id' => 'contact-1'],
                'types' => [[
                    'associationCategory' => 'HUBSPOT_DEFINED',
                    'associationTypeId' => 3,
                ]],
            ],
            [
                'to' => ['id' => 'company-1'],
                'types' => [[
                    'associationCategory' => 'HUBSPOT_DEFINED',
                    'associationTypeId' => 5,
                ]],
            ],
            [
                'to' => ['id' => 'note-1'],
                'types' => [[
                    'associationCategory' => 'HUBSPOT_DEFINED',
                    'associationTypeId' => 213,
                ]],
            ],
        ], $deal['associations']);
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
            (new HubSpotClient($httpClient, 'secret-token', '1351597969', 'default', 'appointmentscheduled'))->submit($this->submission());
            self::fail('A HubSpotException should have been thrown.');
        } catch (HubSpotException $exception) {
            self::assertSame(400, $exception->statusCode, $exception->getMessage());
            self::assertSame('email', $exception->field);
        }
    }

    public function testItReportsAResponseTimeoutAsAHubSpotFailure(): void
    {
        $body = (static function (): \Generator {
            yield '';
        })();
        $httpClient = new MockHttpClient(new MockResponse($body));

        $this->expectException(HubSpotException::class);
        $this->expectExceptionMessage('HubSpot is currently unreachable.');
        (new HubSpotClient($httpClient, 'secret-token', '1351597969', 'default', 'appointmentscheduled'))->submit($this->submission());
    }

    public function testItUpdatesAnExistingCompanyWithTheCountryValueAcceptedByHubSpot(): void
    {
        $requests = [];
        $responses = [
            new MockResponse('{"results":[{"id":"company-1"}]}', ['http_code' => 200]),
            new MockResponse('{"id":"company-1"}', ['http_code' => 200]),
            new MockResponse('{"id":"contact-1"}', ['http_code' => 200]),
            new MockResponse('{}', ['http_code' => 201]),
            new MockResponse('{"id":"note-1"}', ['http_code' => 201]),
            new MockResponse('{"id":"deal-1"}', ['http_code' => 201]),
        ];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$responses): MockResponse {
            $requests[] = [$method, $url, $options];

            return array_shift($responses);
        });

        (new HubSpotClient($httpClient, 'secret-token', '1351597969', 'default', 'appointmentscheduled'))->submit($this->submission('TR'));

        self::assertSame('PATCH', $requests[1][0]);
        self::assertSame('https://api.hubapi.com/crm/objects/2026-03/companies/company-1', $requests[1][1]);
        self::assertSame(['properties' => ['company_country_en' => 'Turkey']], json_decode($requests[1][2]['body'], true, flags: JSON_THROW_ON_ERROR));
        $contact = json_decode($requests[2][2]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Turkey', $contact['properties']['nom_pays_en']);
    }

    private function submission(string $countryCode = ''): ContactSubmission
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
            countryCode: $countryCode,
        );
    }
}
