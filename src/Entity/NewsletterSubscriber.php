<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'newsletter_subscriber')]
#[ORM\UniqueConstraint(name: 'uniq_newsletter_email', columns: ['email'])]
#[ORM\Index(name: 'idx_newsletter_subscribed', columns: ['subscribed_at'])]
class NewsletterSubscriber
{
    public const CONSENT_VERSION = '2026-10-16-v1';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 254)]
    private string $email;

    #[ORM\Column(length: 2)]
    private string $locale;

    #[ORM\Column(length: 1000)]
    private string $originPath;

    #[ORM\Column(length: 32)]
    private string $consentVersion;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $subscribedAt;

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getOriginPath(): string
    {
        return $this->originPath;
    }

    public function getConsentVersion(): string
    {
        return $this->consentVersion;
    }

    public function getSubscribedAt(): \DateTimeImmutable
    {
        return $this->subscribedAt;
    }
}
