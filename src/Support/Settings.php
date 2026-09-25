<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed access to config/lazarus.php, so the rest of the package never deals with mixed.
 */
final class Settings
{
    public function __construct(private readonly Repository $config) {}

    public function string(string $key, string $default = ''): string
    {
        $value = $this->config->get('lazarus.'.$key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->config->get('lazarus.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->config->get('lazarus.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->config->get('lazarus.'.$key);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->config->get('lazarus.'.$key);

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    public function strings(string $key, array $default = []): array
    {
        $value = $this->config->get('lazarus.'.$key);

        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /**
     * A command given either as an argv list or a single shell-like string.
     *
     * @return list<string>|null
     */
    public function command(string $key): ?array
    {
        $value = $this->config->get('lazarus.'.$key);

        if (is_string($value) && trim($value) !== '') {
            $parts = preg_split('/\s+/', trim($value));

            return $parts === false ? null : $parts;
        }

        if (is_array($value) && $value !== []) {
            return array_values(array_map(static fn (mixed $part): string => is_scalar($part) ? (string) $part : '', $value));
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $key): array
    {
        $value = $this->config->get('lazarus.'.$key);

        if (! is_array($value)) {
            return [];
        }

        $section = [];

        foreach ($value as $name => $item) {
            $section[(string) $name] = $item;
        }

        return $section;
    }
}
