<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm\Data;

final readonly class LlmRequest
{
    /**
     * @param  list<Message>  $messages
     */
    public function __construct(
        public string $system,
        public array $messages,
    ) {}

    public function lastUserMessage(): string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]->role === 'user') {
                return $this->messages[$i]->content;
            }
        }

        return '';
    }
}
