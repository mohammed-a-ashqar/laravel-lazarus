<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Events;

use Alashqar\Lazarus\Models\Incident;

/**
 * A new incident was recorded, or a known one happened again.
 */
final readonly class IncidentCaptured
{
    public function __construct(
        public Incident $incident,
        public bool $isNew,
        public bool $fromLog = false,
    ) {}
}
