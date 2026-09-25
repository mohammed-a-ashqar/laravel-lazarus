<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing\Data;

use Alashqar\Lazarus\Healing\HealingState;
use Alashqar\Lazarus\Llm\Data\Usage;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Sandbox\TestRun;
use LogicException;

/**
 * The complete, verified case for a fix: everything a reviewer needs to trust or reject it.
 */
final readonly class HealingReport
{
    public function __construct(
        public Incident $incident,
        public ReproductionTest $test,
        public Patch $patch,
        public Diagnosis $diagnosis,
        public string $diff,
        public TestRun $red,
        public TestRun $green,
        public TestRun $suite,
        public Usage $usage,
        public string $branch,
        public string $baseSha,
        public string $model,
    ) {}

    public static function fromState(HealingState $state, string $model): self
    {
        $missing = static fn (string $what): LogicException => new LogicException("Cannot report a heal without {$what}.");

        return new self(
            incident: $state->incident,
            test: $state->test(),
            patch: $state->patch(),
            diagnosis: $state->diagnosis ?? throw $missing('a diagnosis'),
            diff: $state->diff ?? throw $missing('a diff'),
            red: $state->red ?? throw $missing('the red run'),
            green: $state->green ?? throw $missing('the green run'),
            suite: $state->suite ?? throw $missing('the suite run'),
            usage: $state->usage(),
            branch: $state->worktree()->branch,
            baseSha: $state->worktree()->baseSha,
            model: $model,
        );
    }

    public function title(): string
    {
        return 'fix: '.lcfirst(rtrim($this->patch->summary, '.'));
    }
}
