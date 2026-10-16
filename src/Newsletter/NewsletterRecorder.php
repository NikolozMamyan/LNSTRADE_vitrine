<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Entity\NewsletterSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class NewsletterRecorder
{
    public function __construct(private Connection $connection)
    {
    }

    public function subscribe(string $email, string $locale, string $origin): bool
    {
        $path = parse_url($origin, PHP_URL_PATH);
        $path = is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//') ? mb_substr($path, 0, 1000) : '/';
        try {
            $this->connection->insert('newsletter_subscriber', [
                'email' => $email,
                'locale' => $locale,
                'origin_path' => $path,
                'consent_version' => NewsletterSubscriber::CONSENT_VERSION,
                'subscribed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
