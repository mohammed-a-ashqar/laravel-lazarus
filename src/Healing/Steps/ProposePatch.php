<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing\Steps;

use Alashqar\Lazarus\Enums\HealingStage;
use Alashqar\Lazarus\Healing\Contracts\Step;
use Alashqar\Lazarus\Healing\Data\Patch;
use Alashqar\Lazarus\Healing\EditApplier;
use Alashqar\Lazarus\Healing\EditRejected;
use Alashqar\Lazarus\Healing\HealingAborted;
use Alashqar\Lazarus\Healing\HealingState;
use Alashqar\Lazarus\Healing\Prompts;
use Alashqar\Lazarus\Healing\StepResult;
use Alashqar\Lazarus\Llm\LlmClient;
use Alashqar\Lazarus\Sandbox\PathGuard;
use Alashqar\Lazarus\Sandbox\UnsafePath;
use LogicException;

/**
 * Get a minimal search/replace patch and prove it applies cleanly. Nothing is written yet.
 */
final readonly class ProposePatch implements Step
{
    private const MAX_MALFORMED = 3;

    /**
     * @param  list<string>  $writable
     */
    public function __construct(
        private LlmClient $llm,
        private PathGuard $guard,
        private EditApplier $applier,
        private array $writable,
    ) {}

    public function stage(): HealingStage
    {
        return HealingStage::Patch;
    }

    public function handle(HealingState $state): StepResult
    {
        $worktree = $state->worktree();
        $test = $state->test();

        if ($state->patchConversation === null) {
            $state->patchConversation = $state->conversation(Prompts::system())->user(Prompts::patch(
                $state->incident,
                $state->context,
                $test,
                $state->red ?? throw new LogicException('The bug has not been reproduced yet.'),
                $this->writable,
            ));
        } elseif ($state->patchFeedback !== null) {
            $state->patchConversation->user($state->patchFeedback);
        }

        $conversation = $state->patchConversation;
        $problem = '';

        for ($attempt = 1; $attempt <= self::MAX_MALFORMED; $attempt++) {
            $patch = $this->llm->ask($conversation, Patch::class);

            // A path violation is not a formatting mistake to retry: it ends the heal.
            foreach ($patch->edits as $edit) {
                try {
                    $this->guard->assertWritable($edit->path);
                } catch (UnsafePath $unsafe) {
                    throw new HealingAborted(HealingStage::Patch, 'Blocked by the path guard. '.$unsafe->getMessage(), previous: $unsafe);
                }

                if (str_starts_with(strtolower($edit->path), 'tests/')) {
                    throw new HealingAborted(HealingStage::Patch, sprintf('Blocked: the patch tried to edit "%s". A fix may not change tests.', $edit->path));
                }
            }

            try {
                $state->plannedFiles = $this->applier->plan($patch->edits, $worktree->read(...));
            } catch (EditRejected $rejected) {
                $conversation->user(Prompts::patchFeedback($problem = $rejected->getMessage()));

                continue;
            }

            $state->patch = $patch;

            return new StepResult(sprintf(
                '%s (%d %s in %s)',
                $patch->summary,
                count($patch->edits),
                count($patch->edits) === 1 ? 'edit' : 'edits',
                implode(', ', $patch->files()),
            ));
        }

        throw new HealingAborted(HealingStage::Patch, 'The proposed edits never applied cleanly. Last problem: '.$problem);
    }
}
