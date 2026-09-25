<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm\Contracts;

use Alashqar\Lazarus\Llm\Data\LlmRequest;
use Alashqar\Lazarus\Llm\Data\LlmResponse;
use Alashqar\Lazarus\Llm\LlmException;

interface LlmDriver
{
    /**
     * Send one conversation turn and return the model's text answer with its token usage.
     *
     * @throws LlmException when the provider cannot be reached or refuses the request.
     */
    public function complete(LlmRequest $request): LlmResponse;

    public function name(): string;

    public function model(): string;

    /**
     * Whether the driver has what it needs (an API key, a base URL) to be called at all.
     */
    public function isConfigured(): bool;
}
