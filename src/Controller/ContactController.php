<?php

declare(strict_types=1);

namespace App\Controller;

use App\HubSpot\ContactSubmission;
use App\Prospect\ProspectRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact_submit', methods: ['POST'], format: 'json')]
    public function __invoke(
        Request $request,
        CsrfTokenManagerInterface $csrfTokenManager,
        ValidatorInterface $validator,
        ProspectRecorder $prospectRecorder,
    ): JsonResponse {
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('contact', $this->field($request, '_csrf_token')))) {
            return $this->json(['ok' => false, 'error' => 'invalid_csrf'], Response::HTTP_FORBIDDEN);
        }

        if ('' !== $this->field($request, 'website')) {
            return $this->json(['ok' => true]);
        }

        $locale = $this->field($request, 'locale');
        if (!in_array($locale, ['en', 'fr'], true)) {
            $locale = in_array($request->getLocale(), ['en', 'fr'], true) ? $request->getLocale() : 'en';
        }

        $submission = new ContactSubmission(
            name: $this->field($request, 'name'),
            company: $this->field($request, 'company'),
            email: strtolower($this->field($request, 'email')),
            phone: $this->field($request, 'phone'),
            vat: $this->field($request, 'vat'),
            message: $this->field($request, 'message'),
            context: $this->field($request, 'context'),
            origin: $this->field($request, 'origin'),
            locale: $locale,
        );

        $violations = $validator->validate($submission);
        if (count($violations) > 0) {
            $fields = [];
            foreach ($violations as $violation) {
                $fields[] = $violation->getPropertyPath();
            }

            return $this->json([
                'ok' => false,
                'error' => 'validation',
                'fields' => array_values(array_unique($fields)),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $prospectRecorder->record($submission);

        return $this->json(['ok' => true, 'submitted' => true]);
    }

    private function field(Request $request, string $name): string
    {
        $value = $request->request->all()[$name] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
