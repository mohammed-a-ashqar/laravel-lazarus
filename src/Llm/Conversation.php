<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm;

use Alashqar\Lazarus\Llm\Data\LlmRequest;
use Alashqar\Lazarus\Llm\Data\Message;
use Alashqar\Lazarus\Llm\Data\Usage;
use Alashqar\Lazarus\Redaction\Redactor;

/**
 * One multi-turn exchange with the model, and what it has cost so far.
 */
final class Conversation
{
    /** @var list<Message> */
    private array $messages = [];

    private Usage $usage;

    public function __construct(public readonly string $system)
    {
        $this->usage = new Usage;
    }

    public function user(string $content): self
    {
        $this->messages[] = Message::user($content);

        return $this;
    }

    public function assistant(string $content): self
    {
        $this->messages[] = Message::assistant($content);

        return $this;
    }

    /**
     * The request as it will leave the application: every message passes through the redactor.
     */
    public function toRequest(Redactor $redactor): LlmRequest
    {
        return new LlmRequest(
            $redactor->redact($this->system),
            array_map(static fn (Message $message): Message => new Message($message->role, $redactor->redact($message->content)), $this->messages),
        );
    }

    public function addUsage(Usage $usage): void
    {
        $this->usage = $this->usage->plus($usage);
    }

    public function usage(): Usage
    {
        return $this->usage;
    }

    /**
     * @return list<Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }
}
