<?php

declare(strict_types=1);

namespace App\HubSpot;

interface HubSpotGateway
{
    public function submit(ContactSubmission $submission): void;
}
