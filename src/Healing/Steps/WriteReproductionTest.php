<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing\Steps;

use Alashqar\Lazarus\Enums\HealingStage;
use Alashqar\Lazarus\Events\ReproductionConfirmed;
use Alashqar\Lazarus\Events\ReproductionFailed;
use Alashqar\Lazarus\Healing\Contracts\Step;
use Alashqar\Lazarus\Healing\Data\ReproductionTest;
use Alashqar\Lazarus\Healing\HealingAborted;
use Alashqar\Lazarus\Healing\HealingState;
use Alashqar\Lazarus\Healing\Prompts;
use Alashqar\Lazarus\Healing\StepResult;
use Alashqar\Lazarus\Llm\LlmClient;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Sandbox\PathGuard;
use Alashqar\Lazarus\Sandbox\TestHarness;
use Alashqar\Lazarus\Sandbox\TestRun;
use Alashqar\Lazarus\Sandbox\UnsafePath;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Red: get a test that fails on the current code, and fails for the reason production did.
 */
final readonly class WriteReproductionTest implements Step
{
    public function __construct(
        private LlmClient $llm,
        private PathGuard $guard,
        private TestHarness $harness,
        private Dispatcher $events,
        private int $maxAttempts = 3,
    ) {}

    public function stage(): HealingStage
    {
        return HealingStage::Reproduce;
    }

    public function handle(HealingState $state): StepResult
    {
        $worktree = $state->worktree();
        $conversation = $state->conversation(Prompts::system())
            ->user(Prompts::reproduction($state->incident, $state->context, $worktree));

        $problem = 'no attempt was made';

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $state->reproductionAttempts = $attempt;
            $test = $this->llm->ask($conversation, ReproductionTest::class);

            try {
                $path = $this->guard->assertWritable($test->path);
            } catch (UnsafePath $unsafe) {
                $conversation->user(Prompts::reproductionFeedback($problem = $unsafe->getMessage(), null));

                continue;
            }

            if ($worktree->exists($path)) {
                $conversation->user(Prompts::reproductionFeedback($problem = "{$path} already exists; tests may only be added.", null));

                continue;
            }

            $worktree->write($path, $test->content);
            $run = $this->harness->runTest($worktree, $state->context->framework, $path);
            $problem = self::rejection($state->incident, $run);

            if ($problem === null) {
                $state->test = new ReproductionTest($path, $test->content, $test->reasoning);
                $state->red = $run;
                $this->events->dispatch(new ReproductionConfirmed($state->incident, $state->test, $run, $attempt));

                return new StepResult(
                    sprintf('Reproduced in %s (red after %.1fs, attempt %d)', $path, $run->seconds, $attempt),
                    $run->around($state->incident->shortClass(), 0, 2),
                );
            }

            $worktree->delete($path);
            $conversation->user(Prompts::reproductionFeedback($problem, $run));
        }

        $this->events->dispatch(new ReproductionFailed($state->incident, $problem, $this->maxAttempts));

        throw new HealingAborted(
            HealingStage::Reproduce,
            sprintf('Could not reproduce the bug in %d attempts. Last problem: %s', $this->maxAttempts, $problem),
        );
    }

    /**
     * Why a test run does not count as a reproduction, or null when it does.
     */
    public static function rejection(Incident $incident, TestRun $run): ?string
    {
        if ($run->timedOut) {
            return 'the test timed out.';
        }

        if ($run->passed()) {
            return 'the test passed on the current code, so it does not reproduce the bug.';
        }

        $message = trim(mb_substr($incident->message, 0, 80));

        if ($run->mentions($incident->exception_class) || $run->mentions($incident->shortClass()) || ($message !== '' && $run->mentions($message))) {
            return null;
        }

        return sprintf('the test failed, but not with %s ("%s"). It must fail because of the reported bug.', $incident->shortClass(), $message);
    }
}
