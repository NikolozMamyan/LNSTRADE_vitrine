<?php

declare(strict_types=1);

namespace App\HubSpot;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class HubSpotClient implements HubSpotGateway
{
    private const API_URL = 'https://api.hubapi.com';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(HUBSPOT_ACCESS_TOKEN)%')]
        private string $accessToken,
        #[Autowire('%env(HUBSPOT_OWNER_ID)%')]
        private string $ownerId,
    ) {
    }

    public function submit(ContactSubmission $submission): void
    {
        if ('' === trim($this->accessToken)) {
            throw new HubSpotException('The HubSpot access token is not configured.');
        }

        $companyId = $this->findOrCreateCompany($submission->company);
        $contactId = $this->upsertContact($submission);

        $this->associateContactWithCompany($contactId, $companyId);
        $this->createContactNote($contactId, $submission);
    }

    private function findOrCreateCompany(string $name): string
    {
        $search = $this->request('POST', '/crm/objects/2026-03/companies/search', [
            'json' => [
                'filterGroups' => [[
                    'filters' => [[
                        'propertyName' => 'name',
                        'operator' => 'EQ',
                        'value' => $name,
                    ]],
                ]],
                'properties' => ['name'],
                'limit' => 1,
            ],
        ]);

        $companyId = $search['results'][0]['id'] ?? null;
        if (is_string($companyId) && '' !== $companyId) {
            return $companyId;
        }

        $company = $this->request('POST', '/crm/objects/2026-03/companies', [
            'json' => [
                'properties' => ['name' => $name],
                'associations' => [],
            ],
        ], [201]);

        return $this->requireId($company, 'company');
    }

    private function upsertContact(ContactSubmission $submission): string
    {
        [$firstname, $lastname] = array_pad(preg_split('/\s+/', $submission->name, 2) ?: [], 2, '');
        $properties = array_filter([
            'email' => $submission->email,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'phone' => $submission->phone,
            'company' => $submission->company,
            'hubspot_owner_id' => $this->ownerId,
        ], static fn (string $value): bool => '' !== $value);

        $response = $this->send('PATCH', '/crm/objects/2026-03/contacts/'.rawurlencode($submission->email), [
            'query' => ['idProperty' => 'email'],
            'json' => ['properties' => $properties],
        ]);

        if (404 === $this->statusCode($response)) {
            $contact = $this->request('POST', '/crm/objects/2026-03/contacts', [
                'json' => [
                    'properties' => $properties,
                    'associations' => [],
                ],
            ], [201]);

            return $this->requireId($contact, 'contact');
        }

        $contact = $this->decode($response, [200]);

        return $this->requireId($contact, 'contact');
    }

    private function associateContactWithCompany(string $contactId, string $companyId): void
    {
        $this->request(
            'PUT',
            sprintf('/crm/objects/2026-03/contacts/%s/associations/companies/%s', rawurlencode($contactId), rawurlencode($companyId)),
            ['json' => [[
                'associationCategory' => 'HUBSPOT_DEFINED',
                'associationTypeId' => 1,
            ]]],
            [200, 201],
        );
    }

    private function createContactNote(string $contactId, ContactSubmission $submission): void
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = '' !== $submission->message ? nl2br($escape($submission->message)) : '—';
        $body = sprintf(
            '<strong>Nouvelle demande depuis lnstrade.fr</strong><br><br><strong>Contexte :</strong> %s<br><strong>Entreprise :</strong> %s<br><strong>Contact :</strong> %s<br><strong>E-mail :</strong> %s<br><strong>Téléphone :</strong> %s<br><strong>N° de TVA :</strong> %s<br><strong>Langue :</strong> %s<br><strong>Page d’origine :</strong> %s<br><br><strong>Message :</strong><br>%s',
            $escape($submission->context ?: 'Demande générale'),
            $escape($submission->company),
            $escape($submission->name),
            $escape($submission->email),
            $escape($submission->phone ?: '—'),
            $escape($submission->vat ?: '—'),
            strtoupper($escape($submission->locale)),
            $escape($submission->origin),
            $message,
        );

        $this->request('POST', '/crm/objects/2026-03/notes', [
            'json' => [
                'properties' => [
                    'hs_timestamp' => gmdate('c'),
                    'hs_note_body' => $body,
                ],
                'associations' => [[
                    'to' => ['id' => $contactId],
                    'types' => [[
                        'associationCategory' => 'HUBSPOT_DEFINED',
                        'associationTypeId' => 202,
                    ]],
                ]],
            ],
        ], [201]);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<int>            $expectedStatuses
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = [], array $expectedStatuses = [200]): array
    {
        return $this->decode($this->send($method, $path, $options), $expectedStatuses);
    }

    /** @param array<string, mixed> $options */
    private function send(string $method, string $path, array $options = []): ResponseInterface
    {
        $options['auth_bearer'] = $this->accessToken;
        $options['headers']['Accept'] = 'application/json';

        try {
            return $this->httpClient->request($method, self::API_URL.$path, $options);
        } catch (TransportExceptionInterface $exception) {
            throw new HubSpotException('HubSpot is currently unreachable.', previous: $exception);
        }
    }

    /**
     * @param list<int> $expectedStatuses
     *
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, array $expectedStatuses): array
    {
        try {
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new HubSpotException('HubSpot is currently unreachable.', previous: $exception);
        }

        if (!in_array($status, $expectedStatuses, true)) {
            $correlationId = is_string($data['correlationId'] ?? null) ? ' Correlation: '.$data['correlationId'].'.' : '';
            $message = is_string($data['message'] ?? null) ? $data['message'] : '';
            $field = 400 === $status && (str_contains($message, 'INVALID_EMAIL') || str_contains($message, '"name":"email"'))
                ? 'email'
                : null;

            throw new HubSpotException(
                sprintf('HubSpot returned HTTP %d.%s', $status, $correlationId),
                statusCode: $status,
                field: $field,
            );
        }

        return $data;
    }

    private function statusCode(ResponseInterface $response): int
    {
        try {
            return $response->getStatusCode();
        } catch (TransportExceptionInterface $exception) {
            throw new HubSpotException('HubSpot is currently unreachable.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $data */
    private function requireId(array $data, string $objectType): string
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new HubSpotException(sprintf('HubSpot did not return a %s ID.', $objectType));
        }

        return $id;
    }
}
