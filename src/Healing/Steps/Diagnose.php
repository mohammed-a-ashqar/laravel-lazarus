<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing\Steps;

use Alashqar\Lazarus\Enums\HealingStage;
use Alashqar\Lazarus\Healing\Contracts\Step;
use Alashqar\Lazarus\Healing\Data\Diagnosis;
use Alashqar\Lazarus\Healing\HealingState;
use Alashqar\Lazarus\Healing\Prompts;
use Alashqar\Lazarus\Healing\StepResult;
use Alashqar\Lazarus\Llm\LlmClient;
use LogicException;

/**
 * Explain the verified fix for the reviewer: root cause, reasoning and a confidence score.
 */
final readonly class Diagnose implements Step
{
    public function __construct(private LlmClient $llm) {}

    public function stage(): HealingStage
    {
        return HealingStage::Diagnose;
    }

    public function handle(HealingState $state): StepResult
    {
        $conversation = $state->conversation(Prompts::system())->user(Prompts::diagnosis(
            $state->incident,
            $state->test(),
            $state->diff ?? throw new LogicException('The fix has not been verified yet.'),
            $state->green ?? throw new LogicException('The fix has not been verified yet.'),
        ));

        $state->diagnosis = $this->llm->ask($conversation, Diagnosis::class);

        return new StepResult(sprintf('%s (confidence %d%%)', $state->diagnosis->rootCause, $state->diagnosis->confidencePercent()));
    }
}
