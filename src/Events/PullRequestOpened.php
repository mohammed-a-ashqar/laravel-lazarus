<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Events;

use Alashqar\Lazarus\Healing\Data\HealingReport;
use Alashqar\Lazarus\Models\Incident;

/**
 * The verified fix is waiting for a human review on GitHub.
 */
final readonly class PullRequestOpened
{
    public function __construct(
        public Incident $incident,
        public string $url,
        public HealingReport $report,
    ) {}
}
