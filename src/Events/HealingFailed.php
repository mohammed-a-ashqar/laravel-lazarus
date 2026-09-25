<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Events;

use Alashqar\Lazarus\Enums\HealingStage;
use Alashqar\Lazarus\Models\Incident;

/**
 * A heal stopped without publishing anything. The reason is also stored on the incident.
 */
final readonly class HealingFailed
{
    public function __construct(
        public Incident $incident,
        public string $reason,
        public HealingStage $stage,
    ) {}
}
