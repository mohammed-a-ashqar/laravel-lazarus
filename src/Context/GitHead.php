<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Context;

/**
 * Reads the current commit straight from .git, because spawning `git` on every
 * reported exception would be far too slow for a request path.
 */
final class GitHead
{
    public static function sha(string $root): ?string
    {
        $gitDir = self::gitDir($root);

        if ($gitDir === null || ! is_file($gitDir.'/HEAD')) {
            return null;
        }

        $head = trim((string) file_get_contents($gitDir.'/HEAD'));

        if (preg_match('/^[0-9a-f]{40}$/', $head)) {
            return $head;
        }

        if (! str_starts_with($head, 'ref: ')) {
            return null;
        }

        $ref = substr($head, 5);

        foreach ([$gitDir, self::commonDir($gitDir)] as $dir) {
            if (is_file($dir.'/'.$ref)) {
                return trim((string) file_get_contents($dir.'/'.$ref));
            }

            if (is_file($dir.'/packed-refs')) {
                foreach (file($dir.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (str_ends_with($line, ' '.$ref)) {
                        return substr($line, 0, 40);
                    }
                }
            }
        }

        return null;
    }

    private static function gitDir(string $root): ?string
    {
        $path = $root.'/.git';

        if (is_dir($path)) {
            return $path;
        }

        // Inside a worktree or submodule .git is a file: "gitdir: /path/to/real/dir".
        if (is_file($path) && preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($path), $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private static function commonDir(string $gitDir): string
    {
        $file = $gitDir.'/commondir';

        return is_file($file) ? $gitDir.'/'.trim((string) file_get_contents($file)) : $gitDir;
    }
}
