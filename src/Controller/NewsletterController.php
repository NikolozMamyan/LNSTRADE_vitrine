<?php

declare(strict_types=1);

namespace App\Controller;

use App\Newsletter\NewsletterRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class NewsletterController extends AbstractController
{
    #[Route('/newsletter/subscribe', name: 'app_newsletter_subscribe', methods: ['POST'], format: 'json')]
    public function subscribe(
        Request $request,
        CsrfTokenManagerInterface $csrf,
        ValidatorInterface $validator,
        NewsletterRecorder $recorder,
        #[Autowire(service: 'limiter.newsletter')] RateLimiterFactory $limiter,
    ): JsonResponse {
        if (!$csrf->isTokenValid(new CsrfToken('newsletter', $request->request->getString('_csrf_token')))) {
            return $this->json(['ok' => false, 'error' => 'invalid_csrf'], 403);
        }
        if ('' !== trim($request->request->getString('website'))) {
            return $this->json(['ok' => true, 'subscribed' => false]);
        }
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->json(['ok' => false, 'error' => 'rate_limit'], 429);
        }
        $email = strtolower(trim($request->request->getString('email')));
        if ('1' !== $request->request->getString('consent') || count($validator->validate($email, [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 254)])) > 0) {
            return $this->json(['ok' => false, 'error' => 'validation'], 422);
        }
        $locale = $request->request->getString('locale');
        $created = $recorder->subscribe($email, in_array($locale, ['fr', 'en'], true) ? $locale : 'en', $request->request->getString('origin'));

        return $this->json(['ok' => true, 'subscribed' => true, 'created' => $created]);
    }
}
