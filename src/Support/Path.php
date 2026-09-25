<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Support;

final class Path
{
    /**
     * Forward slashes everywhere, so Windows and Unix paths compare the same way.
     */
    public static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public static function join(string ...$parts): string
    {
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        $joined = implode('/', array_map(static fn (string $part): string => trim(self::normalize($part), '/'), $parts));

        return isset($parts[0]) && str_starts_with(self::normalize($parts[0]), '/') ? '/'.$joined : $joined;
    }
}
