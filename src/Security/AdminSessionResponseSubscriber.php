<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsEventListener]
final class AdminSessionResponseSubscriber
{
    public function __construct(private readonly SessionManager $sessionManager)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('app_admin_logout' === $request->attributes->get('_route')) {
            return;
        }

        $rawToken = $request->attributes->get(AdminSessionAuthenticator::RENEWED_TOKEN_ATTRIBUTE);
        $expiresAt = $request->attributes->get(AdminSessionAuthenticator::RENEWED_EXPIRY_ATTRIBUTE);

        if (is_string($rawToken) && $expiresAt instanceof \DateTimeImmutable) {
            $event->getResponse()->headers->setCookie($this->sessionManager->createCookie($rawToken, $expiresAt));
        }
    }
}
