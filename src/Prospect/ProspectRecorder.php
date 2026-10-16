<?php

declare(strict_types=1);

namespace App\Prospect;

use App\Entity\Prospect;
use App\HubSpot\ContactSubmission;
use App\Visitor\IpGeolocation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Intl\Countries;

final readonly class ProspectRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IpGeolocation $geolocation,
        private LoggerInterface $logger,
        private ProspectAttribution $attribution,
    ) {
    }

    public function record(ContactSubmission $submission, ?string $ipAddress = null, string $userAgent = ''): Prospect
    {
        $ipAddress = null !== $ipAddress && false !== filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : null;
        $prospect = $this->entityManager->getRepository(Prospect::class)->findOneBy([
            'email' => $submission->email,
        ]);
        $now = new \DateTimeImmutable();

        if ($prospect instanceof Prospect) {
            $prospect->record($submission, $now, $ipAddress);
        } else {
            $prospect = new Prospect($submission, $now, $ipAddress);
            $this->entityManager->persist($prospect);
        }

        $source = $this->attribution->resolve($submission->origin, $ipAddress, $userAgent);
        $prospect->setAttribution($source['source'], $source['medium'], $source['campaign']);
        $this->enrichCountry($prospect);
        $this->entityManager->flush();

        return $prospect;
    }

    public function markHubSpotSynced(Prospect $prospect): void
    {
        $prospect->markHubSpotSynced(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function setHubSpotRejected(Prospect $prospect, bool $rejected): void
    {
        if (null !== $prospect->getHubSpotSyncedAt()) {
            return;
        }

        $prospect->setHubSpotRejected($rejected, new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function enrichCountry(Prospect $prospect): void
    {
        if (null === $prospect->getIpAddress() || null !== $prospect->getCountryCode()) {
            return;
        }

        try {
            $countryCode = $this->geolocation->lookup($prospect->getIpAddress())['countryCode'] ?? null;
            if (is_string($countryCode)) {
                $countryCode = strtoupper($countryCode);
                if (Countries::exists($countryCode) || 'XK' === $countryCode) {
                    $prospect->setCountryCode($countryCode);
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Prospect IP geolocation failed.', ['prospect_id' => $prospect->getId(), 'exception' => $exception]);
        }
    }
}
