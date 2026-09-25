<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Events;

use Alashqar\Lazarus\Healing\Data\HealingReport;
use Alashqar\Lazarus\Models\Incident;

/**
 * The patched worktree turned the reproduction test green and the full suite still passes.
 */
final readonly class FixVerified
{
    public function __construct(
        public Incident $incident,
        public HealingReport $report,
    ) {}
}
