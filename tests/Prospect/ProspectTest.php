<?php

declare(strict_types=1);

namespace App\Tests\Prospect;

use App\Entity\Prospect;
use App\HubSpot\ContactSubmission;
use PHPUnit\Framework\TestCase;

final class ProspectTest extends TestCase
{
    public function testItUpdatesAProspectWithoutErasingFilledOptionalFields(): void
    {
        $prospect = new Prospect(
            $this->submission(phone: '+33 1 23 45 67 89', message: 'Premier message'),
            new \DateTimeImmutable('2026-09-24 10:00:00'),
        );
        $prospect->markHubSpotSynced(new \DateTimeImmutable('2026-09-24 10:01:00'));

        $prospect->record(
            $this->submission(name: 'Alice Dupont', phone: '', message: ''),
            new \DateTimeImmutable('2026-09-24 11:00:00'),
        );

        self::assertSame('alice@example.com', $prospect->getEmail());
        self::assertSame('Alice Dupont', $prospect->getName());
        self::assertSame('+33 1 23 45 67 89', $prospect->getPhone());
        self::assertSame('Premier message', $prospect->getMessage());
        self::assertSame(2, $prospect->getSubmissionCount());
        self::assertNull($prospect->getHubSpotSyncedAt());
    }

    public function testItResetsTheCountryWhenTheSubmissionIpChanges(): void
    {
        $prospect = new Prospect($this->submission(), new \DateTimeImmutable('2026-10-15 10:00:00'), '212.32.69.206');
        $prospect->setCountryCode('US');

        $prospect->record($this->submission(), new \DateTimeImmutable('2026-10-15 10:30:00'), '212.32.69.206');
        self::assertSame('US', $prospect->getCountryCode());

        $prospect->record($this->submission(), new \DateTimeImmutable('2026-10-15 11:00:00'), '2001:4860:4860::8888');
        self::assertSame('2001:4860:4860::8888', $prospect->getIpAddress());
        self::assertNull($prospect->getCountryCode());
        self::assertSame('', $prospect->toContactSubmission()->countryCode);
    }

    public function testANewSubmissionKeepsTheSynchronizationRefusal(): void
    {
        $prospect = new Prospect($this->submission(), new \DateTimeImmutable('2026-10-15 10:00:00'));
        $rejectedAt = new \DateTimeImmutable('2026-10-15 10:30:00');
        $prospect->setHubSpotRejected(true, $rejectedAt);

        $prospect->record($this->submission(message: 'Nouvelle demande'), new \DateTimeImmutable('2026-10-15 11:00:00'));

        self::assertSame($rejectedAt, $prospect->getHubSpotRejectedAt());
        self::assertSame('Nouvelle demande', $prospect->getMessage());
        self::assertSame(2, $prospect->getSubmissionCount());
        self::assertNull($prospect->getHubSpotSyncedAt());
    }

    private function submission(
        string $name = 'Alice Martin',
        string $phone = '',
        string $message = '',
    ): ContactSubmission {
        return new ContactSubmission(
            name: $name,
            company: 'Example SAS',
            email: 'alice@example.com',
            phone: $phone,
            vat: '',
            message: $message,
            context: 'Demande générale',
            origin: 'https://lnstrade.fr/fr/',
            locale: 'fr',
        );
    }
}
