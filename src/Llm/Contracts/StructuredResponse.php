<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm\Contracts;

use Alashqar\Lazarus\Llm\InvalidLlmResponse;

/**
 * A DTO the model must return as strict JSON.
 */
interface StructuredResponse
{
    /**
     * The JSON shape, shown to the model in the prompt and again when it gets it wrong.
     */
    public static function schema(): string;

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidLlmResponse with a message the model can act on.
     */
    public static function fromLlm(array $data): static;
}
