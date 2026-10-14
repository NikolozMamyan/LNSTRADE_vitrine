<?php

declare(strict_types=1);

namespace App\Prospect;

use App\Entity\Prospect;
use App\HubSpot\HubSpotGateway;

final readonly class ProspectHubSpotSender
{
    public function __construct(
        private HubSpotGateway $hubSpot,
        private ProspectRecorder $recorder,
    ) {
    }

    public function send(Prospect $prospect): void
    {
        if (null !== $prospect->getHubSpotSyncedAt()) {
            return;
        }

        $this->hubSpot->submit($prospect->toContactSubmission());
        $this->recorder->markHubSpotSynced($prospect);
    }
}
