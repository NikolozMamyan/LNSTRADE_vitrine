<?php

declare(strict_types=1);

namespace App\Prospect;

use App\Entity\Prospect;
use App\HubSpot\ContactSubmission;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProspectRecorder
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function record(ContactSubmission $submission): Prospect
    {
        $prospect = $this->entityManager->getRepository(Prospect::class)->findOneBy([
            'email' => $submission->email,
        ]);
        $now = new \DateTimeImmutable();

        if ($prospect instanceof Prospect) {
            $prospect->record($submission, $now);
        } else {
            $prospect = new Prospect($submission, $now);
            $this->entityManager->persist($prospect);
        }

        $this->entityManager->flush();

        return $prospect;
    }

    public function markHubSpotSynced(Prospect $prospect): void
    {
        $prospect->markHubSpotSynced(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}
