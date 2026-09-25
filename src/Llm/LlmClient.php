<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm;

use Alashqar\Lazarus\Llm\Contracts\LlmDriver;
use Alashqar\Lazarus\Llm\Contracts\StructuredResponse;
use Alashqar\Lazarus\Redaction\Redactor;
use JsonException;

/**
 * Asks for strict JSON, validates it into a DTO and, when the model gets the format wrong,
 * tells it exactly what was wrong and asks again. Every request is redacted and budgeted.
 */
final readonly class LlmClient
{
    public function __construct(
        private LlmDriver $driver,
        private Redactor $redactor,
        private TokenBudget $budget,
        private int $maxInvalidResponses = 2,
    ) {}

    /**
     * @template T of StructuredResponse
     *
     * @param  class-string<T>  $shape
     * @return T
     *
     * @throws InvalidLlmResponse when every attempt came back invalid.
     * @throws BudgetExceeded
     * @throws LlmException
     */
    public function ask(Conversation $conversation, string $shape): StructuredResponse
    {
        $attempts = 1 + max(0, $this->maxInvalidResponses);

        for ($attempt = 1; ; $attempt++) {
            $this->budget->ensureAvailable();

            $response = $this->driver->complete($conversation->toRequest($this->redactor));

            $this->budget->record($response->usage);
            $conversation->addUsage($response->usage);
            $conversation->assistant($response->content);

            try {
                return $shape::fromLlm(self::decode($response->content));
            } catch (InvalidLlmResponse $invalid) {
                if ($attempt >= $attempts) {
                    throw new InvalidLlmResponse(sprintf('The model returned invalid output %d times. Last problem: %s', $attempts, $invalid->getMessage()), previous: $invalid);
                }

                $conversation->user(implode("\n\n", [
                    'Your previous answer could not be used: '.$invalid->getMessage(),
                    'Reply again with ONLY a JSON object (no prose, no Markdown fences) of this shape:',
                    $shape::schema(),
                ]));
            }
        }
    }

    public function driver(): LlmDriver
    {
        return $this->driver;
    }

    /**
     * Pull the JSON object out of a model answer, tolerating Markdown fences and chatter.
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidLlmResponse
     */
    public static function decode(string $content): array
    {
        $json = trim($content);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $json, $fenced)) {
            $json = $fenced[1];
        } elseif (! str_starts_with($json, '{')) {
            $start = strpos($json, '{');
            $end = strrpos($json, '}');

            if ($start === false || $end === false || $end < $start) {
                throw new InvalidLlmResponse('it did not contain a JSON object.');
            }

            $json = substr($json, $start, $end - $start + 1);
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidLlmResponse('it was not valid JSON ('.$exception->getMessage().').');
        }

        if (! is_array($data) || array_is_list($data) && $data !== []) {
            throw new InvalidLlmResponse('the top-level JSON value must be an object.');
        }

        return $data;
    }
}
