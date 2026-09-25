<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Jobs;

use Alashqar\Lazarus\Healing\Healer;
use Alashqar\Lazarus\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Heals one incident in the background. Unique per fingerprint, and never retried: a heal
 * spends tokens, so a failure is recorded on the incident instead of being repeated.
 */
final class HealIncident implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $incidentId,
        public readonly string $fingerprint,
    ) {}

    public function uniqueId(): string
    {
        return $this->fingerprint;
    }

    public function handle(Healer $healer): void
    {
        $incident = Incident::query()->find($this->incidentId);

        if ($incident instanceof Incident && ! $incident->status->isFinal()) {
            $healer->heal($incident);
        }
    }
}
