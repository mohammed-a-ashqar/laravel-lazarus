<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing\Steps;

use Alashqar\Lazarus\Enums\HealingStage;
use Alashqar\Lazarus\Healing\Contracts\Step;
use Alashqar\Lazarus\Healing\HealingState;
use Alashqar\Lazarus\Healing\PatchRejected;
use Alashqar\Lazarus\Healing\StepResult;
use Alashqar\Lazarus\Sandbox\TestHarness;

/**
 * Green: write the patch, then require the reproduction test AND the full suite to pass.
 */
final readonly class ApplyAndVerify implements Step
{
    public function __construct(private TestHarness $harness) {}

    public function stage(): HealingStage
    {
        return HealingStage::Verify;
    }

    /**
     * @throws PatchRejected with the run that failed, after restoring the original files.
     */
    public function handle(HealingState $state): StepResult
    {
        $worktree = $state->worktree();
        $test = $state->test();
        $originals = [];

        foreach ($state->plannedFiles as $path => $contents) {
            $originals[$path] = (string) $worktree->read($path);
            $worktree->write($path, $contents);
        }

        $restore = static function () use ($worktree, $originals): void {
            foreach ($originals as $path => $contents) {
                $worktree->write($path, $contents);
            }
        };

        $green = $this->harness->runTest($worktree, $state->context->framework, $test->path);

        if (! $green->passed()) {
            $restore();

            throw new PatchRejected('the reproduction test still fails with the patch applied.', $green);
        }

        $suite = $this->harness->runSuite($worktree, $state->context->framework);

        if (! $suite->passed()) {
            $restore();

            throw new PatchRejected('the reproduction test passes, but the full test suite now fails.', $suite);
        }

        $state->green = $green;
        $state->suite = $suite;
        $state->diff = $worktree->diff([$test->path, ...array_keys($state->plannedFiles)]);

        return new StepResult(
            sprintf('Reproduction test is green (%.1fs) and the full suite passes (%.1fs)', $green->seconds, $suite->seconds),
            $suite->excerpt(6),
        );
    }
}
