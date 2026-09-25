<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm\Drivers;

/**
 * Typed reads from one entry of `lazarus.llm.drivers`.
 */
final readonly class DriverConfig
{
    /**
     * @param  array<array-key, mixed>  $values
     */
    public function __construct(private array $values) {}

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return array{input?: mixed, output?: mixed}
     */
    public function pricing(): array
    {
        $pricing = $this->values['pricing'] ?? [];

        return is_array($pricing) ? ['input' => $pricing['input'] ?? 0, 'output' => $pricing['output'] ?? 0] : [];
    }
}
