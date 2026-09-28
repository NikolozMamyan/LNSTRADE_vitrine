<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AdminSession;
use App\Entity\AdminUser;
use PHPUnit\Framework\TestCase;

final class AdminSessionTest extends TestCase
{
    public function testItCanBeProlongedAndRevokedIndependently(): void
    {
        $createdAt = new \DateTimeImmutable('2026-09-28 10:00:00');
        $user = new AdminUser(' Admin@Example.com ');
        $session = new AdminSession(
            $user,
            str_repeat('a', 64),
            $createdAt,
            $createdAt->modify('+30 minutes'),
            '127.0.0.1',
            'Test browser',
        );

        self::assertSame('admin@example.com', $user->getUserIdentifier());
        self::assertTrue($session->isValidAt($createdAt->modify('+29 minutes')));
        self::assertFalse($session->isValidAt($createdAt->modify('+30 minutes')));

        $session->prolong($createdAt->modify('+20 minutes'), $createdAt->modify('+50 minutes'));
        self::assertTrue($session->isValidAt($createdAt->modify('+40 minutes')));

        $session->revoke($createdAt->modify('+25 minutes'));
        self::assertFalse($session->isValidAt($createdAt->modify('+26 minutes')));
    }
}
