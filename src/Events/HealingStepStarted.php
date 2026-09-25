<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Events;

use Alashqar\Lazarus\Enums\HealingStage;
use Alashqar\Lazarus\Models\Incident;

/**
 * Progress: a pipeline step has started. Drives the live output of lazarus:heal.
 */
final readonly class HealingStepStarted
{
    public function __construct(
        public Incident $incident,
        public HealingStage $stage,
        public string $description,
    ) {}
}
