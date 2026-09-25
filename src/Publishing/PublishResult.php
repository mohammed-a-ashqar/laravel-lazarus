<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Publishing;

final readonly class PublishResult
{
    public function __construct(
        public string $description,
        public ?string $url = null,
        public ?string $path = null,
    ) {}

    public function isPullRequest(): bool
    {
        return $this->url !== null;
    }
}
