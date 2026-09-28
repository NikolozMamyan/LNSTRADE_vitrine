<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AdminUser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class AdminSessionAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const RENEWED_TOKEN_ATTRIBUTE = '_admin_session_renewed_token';
    public const RENEWED_EXPIRY_ATTRIBUTE = '_admin_session_renewed_expiry';

    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if ('app_admin_login' === $request->attributes->get('_route') && $request->isMethod('POST')) {
            return true;
        }

        return str_starts_with($request->getPathInfo(), '/admin')
            && $request->cookies->has(SessionManager::COOKIE_NAME);
    }

    public function authenticate(Request $request): Passport
    {
        if ('app_admin_login' === $request->attributes->get('_route') && $request->isMethod('POST')) {
            $email = strtolower(trim($request->request->getString('email')));

            return new Passport(
                new UserBadge($email),
                new PasswordCredentials($request->request->getString('password')),
                [new CsrfTokenBadge('authenticate', $request->request->getString('_csrf_token'))],
            );
        }

        $cookieValue = $request->cookies->get(SessionManager::COOKIE_NAME, '');
        $rawToken = is_string($cookieValue) ? $cookieValue : '';
        $session = $this->sessionManager->resume($rawToken);
        if (null === $session) {
            throw new BadCredentialsException('The admin session is invalid or expired.');
        }

        $request->attributes->set(self::RENEWED_TOKEN_ATTRIBUTE, $rawToken);
        $request->attributes->set(self::RENEWED_EXPIRY_ATTRIBUTE, $session->getExpiresAt());
        $user = $session->getUser();

        return new SelfValidatingPassport(new UserBadge(
            $user->getUserIdentifier(),
            static fn (): AdminUser => $user,
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ('app_admin_login' !== $request->attributes->get('_route') || !$request->isMethod('POST')) {
            return null;
        }

        $user = $token->getUser();
        if (!$user instanceof AdminUser) {
            throw new \LogicException('The authenticated user must be an administrator.');
        }

        $response = new RedirectResponse($this->urlGenerator->generate('app_admin_dashboard'));
        $response->headers->setCookie($this->sessionManager->start($user, $request));

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $response = new RedirectResponse($this->urlGenerator->generate('app_admin_login'));

        if ('app_admin_login' === $request->attributes->get('_route')) {
            $request->getSession()->getFlashBag()->add('admin_login_error', 'Identifiants invalides.');
        } else {
            $request->getSession()->getFlashBag()->add('admin_login_error', 'Votre session a expiré.');
            $response->headers->setCookie($this->sessionManager->clearCookie());
        }

        return $response;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('app_admin_login'));
    }
}
