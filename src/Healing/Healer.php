<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing;

use Alashqar\Lazarus\Enums\HealingStage;
use Alashqar\Lazarus\Enums\IncidentStatus;
use Alashqar\Lazarus\Events\FixVerified;
use Alashqar\Lazarus\Events\HealingFailed;
use Alashqar\Lazarus\Events\HealingStepCompleted;
use Alashqar\Lazarus\Events\HealingStepStarted;
use Alashqar\Lazarus\Events\PullRequestOpened;
use Alashqar\Lazarus\Healing\Contracts\Step;
use Alashqar\Lazarus\Healing\Data\HealingReport;
use Alashqar\Lazarus\Healing\Steps\ApplyAndVerify;
use Alashqar\Lazarus\Healing\Steps\Diagnose;
use Alashqar\Lazarus\Healing\Steps\ProposePatch;
use Alashqar\Lazarus\Healing\Steps\WriteReproductionTest;
use Alashqar\Lazarus\Llm\LlmClient;
use Alashqar\Lazarus\Llm\TokenBudget;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Publishing\Publisher;
use Alashqar\Lazarus\Sandbox\GitWorkspace;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * The pipeline: reproduce (red), patch, verify (green + full suite), diagnose, publish.
 *
 * Each step either proves its part or aborts the heal. Whatever happens, the worktree is
 * removed and the reason is recorded on the incident. A failed heal publishes nothing.
 */
final readonly class Healer
{
    public function __construct(
        private GitWorkspace $workspace,
        private WriteReproductionTest $reproduce,
        private ProposePatch $propose,
        private ApplyAndVerify $verify,
        private Diagnose $diagnose,
        private Publisher $publisher,
        private LlmClient $llm,
        private TokenBudget $budget,
        private Dispatcher $events,
        private int $maxPatchAttempts = 2,
    ) {}

    public function heal(Incident $incident): ?HealingReport
    {
        if ($incident->status === IncidentStatus::Ignored) {
            return null;
        }

        $state = new HealingState($incident, $incident->incidentContext());

        try {
            $this->preflight($state);

            $this->run($state, $this->reproduce);
            $incident->transitionTo(IncidentStatus::Reproduced);

            $this->patchUntilVerified($state);
            $incident->transitionTo(IncidentStatus::Verified);

            $this->run($state, $this->diagnose);

            return $this->publish($state);
        } catch (HealingAborted $aborted) {
            $this->fail($state, $aborted->stage, $aborted->getMessage());
        } catch (Throwable $error) {
            $this->fail($state, $state->stage, $error->getMessage());
        } finally {
            if ($state->worktree !== null) {
                $this->workspace->remove($state->worktree);
            }

            $usage = $state->usage();
            $incident->addUsage($usage->totalTokens(), $usage->cost);
            $incident->save();
        }

        return null;
    }

    private function preflight(HealingState $state): void
    {
        $state->stage = HealingStage::Preflight;

        $state->incident->transitionTo(IncidentStatus::Analyzing);
        $this->started($state, HealingStage::Preflight->label());

        $this->budget->ensureAvailable();
        $started = microtime(true);
        $state->worktree = $this->workspace->create($state->incident->fingerprint);

        $this->completed($state, 'Worktree '.$state->worktree->path.' on branch '.$state->worktree->branch, null, $started);
    }

    /**
     * Propose, apply and verify; a patch that fails verification is sent back with the output.
     */
    private function patchUntilVerified(HealingState $state): void
    {
        for ($attempt = 1; ; $attempt++) {
            $this->run($state, $this->propose);
            $state->incident->transitionTo(IncidentStatus::Patched);

            try {
                $this->run($state, $this->verify);

                return;
            } catch (PatchRejected $rejected) {
                $this->events->dispatch(new HealingStepCompleted($state->incident, HealingStage::Verify, false, 'Rejected: '.$rejected->getMessage(), $rejected->run->excerpt(15)));

                if ($attempt >= $this->maxPatchAttempts) {
                    throw new HealingAborted(
                        HealingStage::Verify,
                        sprintf('No patch survived verification after %d attempts. Last problem: %s', $attempt, $rejected->getMessage()),
                        $rejected->run->excerpt(40),
                    );
                }

                $state->patchFeedback = Prompts::patchFeedback($rejected->getMessage(), $rejected->run);
            }
        }
    }

    private function publish(HealingState $state): HealingReport
    {
        $worktree = $state->worktree();
        $report = HealingReport::fromState($state, $this->llm->driver()->model());

        $worktree->commit(
            [$report->test->path, ...$report->patch->files()],
            $report->title()."\n\nReproduced and verified by Lazarus.\nIncident: ".$state->incident->fingerprint,
        );

        $this->events->dispatch(new FixVerified($state->incident, $report));

        $state->stage = HealingStage::Publish;
        $this->started($state, HealingStage::Publish->label());
        $started = microtime(true);

        $result = $this->publisher->publish($report, $worktree);

        $state->incident->pr_url = $result->url;
        $state->incident->report_path = $result->path;
        $state->incident->transitionTo($result->isPullRequest() ? IncidentStatus::PrOpened : IncidentStatus::Verified);

        $this->completed($state, $result->description, null, $started);

        if ($result->url !== null) {
            $this->events->dispatch(new PullRequestOpened($state->incident, $result->url, $report));
        }

        return $report;
    }

    private function run(HealingState $state, Step $step): void
    {
        $state->stage = $step->stage();
        $this->started($state, $step->stage()->label());
        $started = microtime(true);

        $result = $step->handle($state);

        $this->completed($state, $result->summary, $result->details, $started);
    }

    private function started(HealingState $state, string $description): void
    {
        $this->events->dispatch(new HealingStepStarted($state->incident, $state->stage, $description));
    }

    private function completed(HealingState $state, string $summary, ?string $details, float $started): void
    {
        $this->events->dispatch(new HealingStepCompleted($state->incident, $state->stage, true, $summary, $details, round(microtime(true) - $started, 2)));
    }

    private function fail(HealingState $state, HealingStage $stage, string $reason): void
    {
        $reason = mb_substr($reason, 0, 2000);

        $this->events->dispatch(new HealingStepCompleted($state->incident, $stage, false, $reason));

        $state->incident->transitionTo(IncidentStatus::Failed, $reason);

        $this->events->dispatch(new HealingFailed($state->incident, $reason, $stage));
    }
}
