<?php

declare(strict_types=1);

namespace App\HubSpot;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ContactSubmission
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $company,
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 254)]
        public string $email,
        #[Assert\Length(max: 100)]
        public string $phone,
        #[Assert\Length(max: 100)]
        public string $vat,
        #[Assert\Length(max: 10000)]
        public string $message,
        #[Assert\Length(max: 255)]
        public string $context,
        #[Assert\Length(max: 1000)]
        public string $origin,
        #[Assert\Choice(choices: ['en', 'fr'])]
        public string $locale,
    ) {
    }
}
