<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Events;

use Alashqar\Lazarus\Enums\HealingStage;
use Alashqar\Lazarus\Models\Incident;

/**
 * Progress: a pipeline step has finished.
 */
final readonly class HealingStepCompleted
{
    public function __construct(
        public Incident $incident,
        public HealingStage $stage,
        public bool $succeeded,
        public string $summary,
        public ?string $details = null,
        public float $seconds = 0.0,
    ) {}
}
