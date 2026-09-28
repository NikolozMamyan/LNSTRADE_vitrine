<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener(event: LogoutEvent::class)]
final class AdminLogoutSubscriber
{
    public function __construct(private readonly SessionManager $sessionManager)
    {
    }

    public function __invoke(LogoutEvent $event): void
    {
        $cookieValue = $event->getRequest()->cookies->get(SessionManager::COOKIE_NAME, '');
        $rawToken = is_string($cookieValue) ? $cookieValue : '';
        $this->sessionManager->revoke($rawToken);

        if (null !== $event->getResponse()) {
            $event->getResponse()->headers->setCookie($this->sessionManager->clearCookie());
        }
    }
}
