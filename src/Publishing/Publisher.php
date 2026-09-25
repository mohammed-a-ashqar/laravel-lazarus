<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Publishing;

use Alashqar\Lazarus\Healing\Data\HealingReport;
use Alashqar\Lazarus\Sandbox\Worktree;

interface Publisher
{
    /**
     * Hand a verified, committed fix to humans. Must never merge anything.
     */
    public function publish(HealingReport $report, Worktree $worktree): PublishResult;

    public function name(): string;
}
