<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing\Contracts;

use Alashqar\Lazarus\Enums\HealingStage;
use Alashqar\Lazarus\Healing\HealingAborted;
use Alashqar\Lazarus\Healing\HealingState;
use Alashqar\Lazarus\Healing\StepResult;

interface Step
{
    public function stage(): HealingStage;

    /**
     * Establish one fact about the fix and record it on the state.
     *
     * @throws HealingAborted when the fact cannot be proven.
     */
    public function handle(HealingState $state): StepResult;
}
