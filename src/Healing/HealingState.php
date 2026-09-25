<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing;

use Alashqar\Lazarus\Context\IncidentContext;
use Alashqar\Lazarus\Enums\HealingStage;
use Alashqar\Lazarus\Healing\Data\Diagnosis;
use Alashqar\Lazarus\Healing\Data\Patch;
use Alashqar\Lazarus\Healing\Data\ReproductionTest;
use Alashqar\Lazarus\Llm\Conversation;
use Alashqar\Lazarus\Llm\Data\Usage;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Sandbox\TestRun;
use Alashqar\Lazarus\Sandbox\Worktree;
use LogicException;

/**
 * What the pipeline has established so far. Each step reads its inputs from here and
 * records its proof here; nothing is trusted unless an earlier step put it in.
 */
final class HealingState
{
    public HealingStage $stage = HealingStage::Preflight;

    public ?Worktree $worktree = null;

    public ?ReproductionTest $test = null;

    public ?TestRun $red = null;

    public int $reproductionAttempts = 0;

    public ?Patch $patch = null;

    /** @var array<string, string> Path => patched contents, validated but not yet written. */
    public array $plannedFiles = [];

    public ?string $patchFeedback = null;

    public ?Conversation $patchConversation = null;

    public ?TestRun $green = null;

    public ?TestRun $suite = null;

    public ?string $diff = null;

    public ?Diagnosis $diagnosis = null;

    /** @var list<Conversation> */
    private array $conversations = [];

    public function __construct(
        public readonly Incident $incident,
        public readonly IncidentContext $context,
    ) {}

    public function conversation(string $system): Conversation
    {
        return $this->conversations[] = new Conversation($system);
    }

    public function usage(): Usage
    {
        return array_reduce(
            $this->conversations,
            static fn (Usage $total, Conversation $conversation): Usage => $total->plus($conversation->usage()),
            new Usage,
        );
    }

    public function worktree(): Worktree
    {
        return $this->worktree ?? throw new LogicException('No worktree has been created yet.');
    }

    public function test(): ReproductionTest
    {
        return $this->test ?? throw new LogicException('The bug has not been reproduced yet.');
    }

    public function patch(): Patch
    {
        return $this->patch ?? throw new LogicException('No patch has been proposed yet.');
    }
}
