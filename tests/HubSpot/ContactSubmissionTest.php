<?php

declare(strict_types=1);

namespace App\Tests\HubSpot;

use App\HubSpot\ContactSubmission;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ContactSubmissionTest extends TestCase
{
    public function testItAcceptsALocalDevelopmentOrigin(): void
    {
        $submission = new ContactSubmission(
            name: 'Alice Martin',
            company: 'Example SAS',
            email: 'alice@example.com',
            phone: '',
            vat: '',
            message: '',
            context: 'Demande générale',
            origin: 'http://localhost:8000/en/',
            locale: 'en',
        );

        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertCount(0, $validator->validate($submission));
    }
}
