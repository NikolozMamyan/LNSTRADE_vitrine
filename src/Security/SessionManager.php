<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AdminSession;
use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class SessionManager
{
    public const COOKIE_NAME = '__Secure-lns_admin_session';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(int:ADMIN_SESSION_IDLE_TTL)%')]
        private readonly int $idleTtl,
        #[Autowire('%env(int:ADMIN_SESSION_ABSOLUTE_TTL)%')]
        private readonly int $absoluteTtl,
    ) {
        if ($this->idleTtl < 60 || $this->absoluteTtl < $this->idleTtl) {
            throw new \InvalidArgumentException('Invalid admin session lifetimes.');
        }
    }

    public function start(AdminUser $user, Request $request): Cookie
    {
        $now = new \DateTimeImmutable();
        $rawToken = $this->generateToken();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->idleTtl));
        $userAgent = $request->headers->get('User-Agent');

        $session = new AdminSession(
            $user,
            $this->hash($rawToken),
            $now,
            $expiresAt,
            $request->getClientIp(),
            null === $userAgent ? null : substr($userAgent, 0, 500),
        );

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $this->createCookie($rawToken, $expiresAt);
    }

    public function resume(string $rawToken): ?AdminSession
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $rawToken)) {
            return null;
        }

        $session = $this->entityManager->getRepository(AdminSession::class)->findOneBy([
            'tokenHash' => $this->hash($rawToken),
        ]);

        $now = new \DateTimeImmutable();
        if (!$session instanceof AdminSession || !$session->isValidAt($now)) {
            return null;
        }

        $absoluteExpiry = $session->getCreatedAt()->modify(sprintf('+%d seconds', $this->absoluteTtl));
        if ($absoluteExpiry <= $now) {
            $session->revoke($now);
            $this->entityManager->flush();

            return null;
        }

        $idleExpiry = $now->modify(sprintf('+%d seconds', $this->idleTtl));
        $session->prolong($now, min($idleExpiry, $absoluteExpiry));
        $this->entityManager->flush();

        return $session;
    }

    public function revoke(string $rawToken): void
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $rawToken)) {
            return;
        }

        $session = $this->entityManager->getRepository(AdminSession::class)->findOneBy([
            'tokenHash' => $this->hash($rawToken),
        ]);

        if ($session instanceof AdminSession) {
            $session->revoke(new \DateTimeImmutable());
            $this->entityManager->flush();
        }
    }

    public function revokeAll(AdminUser $user): int
    {
        return $this->entityManager->createQueryBuilder()
            ->update(AdminSession::class, 'session')
            ->set('session.revokedAt', ':now')
            ->where('session.user = :user')
            ->andWhere('session.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function revokeSession(AdminUser $user, int $sessionId): bool
    {
        $session = $this->entityManager->getRepository(AdminSession::class)->findOneBy([
            'id' => $sessionId,
            'user' => $user,
        ]);

        if (!$session instanceof AdminSession) {
            return false;
        }

        $session->revoke(new \DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }

    /** @return list<AdminSession> */
    public function activeSessions(AdminUser $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('session')
            ->from(AdminSession::class, 'session')
            ->where('session.user = :user')
            ->andWhere('session.revokedAt IS NULL')
            ->andWhere('session.expiresAt > :now')
            ->orderBy('session.lastUsedAt', 'DESC')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    public function createCookie(string $rawToken, \DateTimeImmutable $expiresAt): Cookie
    {
        return Cookie::create(
            self::COOKIE_NAME,
            $rawToken,
            $expiresAt,
            '/admin',
            secure: true,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }

    public function clearCookie(): Cookie
    {
        return Cookie::create(
            self::COOKIE_NAME,
            '',
            1,
            '/admin',
            secure: true,
            httpOnly: true,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
