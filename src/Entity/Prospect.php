<?php

declare(strict_types=1);

namespace App\Entity;

use App\HubSpot\ContactSubmission;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'prospect')]
#[ORM\UniqueConstraint(name: 'uniq_prospect_email', columns: ['email'])]
class Prospect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 254)]
    private string $email;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 200)]
    private string $company;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $vat = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $context = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $origin = null;

    #[ORM\Column(length: 2)]
    private string $locale;

    #[ORM\Column]
    private int $submissionCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSubmittedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $hubSpotSyncedAt = null;

    public function __construct(ContactSubmission $submission, \DateTimeImmutable $submittedAt)
    {
        $this->email = $submission->email;
        $this->createdAt = $submittedAt;
        $this->record($submission, $submittedAt);
    }

    public function record(ContactSubmission $submission, \DateTimeImmutable $submittedAt): void
    {
        $this->name = $submission->name;
        $this->company = $submission->company;
        $this->locale = $submission->locale;
        $this->replaceWhenFilled('phone', $submission->phone);
        $this->replaceWhenFilled('vat', $submission->vat);
        $this->replaceWhenFilled('message', $submission->message);
        $this->replaceWhenFilled('context', $submission->context);
        $this->replaceWhenFilled('origin', $submission->origin);
        ++$this->submissionCount;
        $this->updatedAt = $submittedAt;
        $this->lastSubmittedAt = $submittedAt;
        $this->hubSpotSyncedAt = null;
    }

    public function markHubSpotSynced(\DateTimeImmutable $syncedAt): void
    {
        $this->hubSpotSyncedAt = $syncedAt;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getSubmissionCount(): int
    {
        return $this->submissionCount;
    }

    public function getHubSpotSyncedAt(): ?\DateTimeImmutable
    {
        return $this->hubSpotSyncedAt;
    }

    private function replaceWhenFilled(string $property, string $value): void
    {
        if ('' !== $value) {
            $this->{$property} = $value;
        }
    }
}
