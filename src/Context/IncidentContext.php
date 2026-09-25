<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Context;

use Alashqar\Lazarus\Enums\TestFramework;

/**
 * Everything a model needs to understand an incident, already redacted.
 */
final readonly class IncidentContext
{
    /**
     * @param  list<StackFrame>  $frames  Application frames only, innermost first.
     * @param  array<string, string>  $sources  Project-relative path => full (size-capped) source.
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $queries
     */
    public function __construct(
        public array $frames,
        public array $sources,
        public ?string $route,
        public ?string $method,
        public array $input,
        public array $queries,
        public ?string $gitSha,
        public TestFramework $framework,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $frames = [];

        foreach (is_array($data['frames'] ?? null) ? $data['frames'] : [] as $frame) {
            if (is_array($frame)) {
                $frames[] = StackFrame::fromArray($frame);
            }
        }

        $sources = [];

        foreach (is_array($data['sources'] ?? null) ? $data['sources'] : [] as $path => $source) {
            if (is_string($source)) {
                $sources[(string) $path] = $source;
            }
        }

        $queries = array_values(array_filter(
            is_array($data['queries'] ?? null) ? $data['queries'] : [],
            'is_string',
        ));

        return new self(
            $frames,
            $sources,
            is_string($data['route'] ?? null) ? $data['route'] : null,
            is_string($data['method'] ?? null) ? $data['method'] : null,
            is_array($data['input'] ?? null) ? $data['input'] : [],
            $queries,
            is_string($data['git_sha'] ?? null) ? $data['git_sha'] : null,
            TestFramework::tryFrom(is_string($data['framework'] ?? null) ? $data['framework'] : '') ?? TestFramework::Unknown,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'frames' => array_map(static fn (StackFrame $frame): array => $frame->toArray(), $this->frames),
            'sources' => $this->sources,
            'route' => $this->route,
            'method' => $this->method,
            'input' => $this->input,
            'queries' => $this->queries,
            'git_sha' => $this->gitSha,
            'framework' => $this->framework->value,
        ];
    }
}
