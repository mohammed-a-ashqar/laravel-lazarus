<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing;

use Alashqar\Lazarus\Healing\Data\FileEdit;
use Closure;

/**
 * Applies search/replace edits in memory. Every search block must match exactly once, so an
 * edit can never land somewhere the model did not intend.
 */
final class EditApplier
{
    /**
     * @param  list<FileEdit>  $edits
     * @param  Closure(string): (string|null)  $read  Returns the current file contents, or null if missing.
     * @return array<string, string> Path => new contents, for every touched file.
     *
     * @throws EditRejected
     */
    public function plan(array $edits, Closure $read): array
    {
        $files = [];

        foreach ($edits as $index => $edit) {
            $current = $files[$edit->path] ?? $read($edit->path);

            if ($current === null) {
                throw new EditRejected(sprintf('Edit %d targets "%s", which does not exist. Edits may only change existing files.', $index, $edit->path));
            }

            $files[$edit->path] = self::apply($current, $edit, $index);
        }

        return $files;
    }

    /**
     * @throws EditRejected
     */
    public static function apply(string $contents, FileEdit $edit, int $index = 0): string
    {
        $search = str_replace("\r\n", "\n", $edit->search);
        $replace = str_replace("\r\n", "\n", $edit->replace);

        // Keep the file's own line endings (a Windows checkout may use CRLF).
        if (str_contains($contents, "\r\n")) {
            $search = str_replace("\n", "\r\n", $search);
            $replace = str_replace("\n", "\r\n", $replace);
        }

        if (trim($search) === '') {
            throw new EditRejected(sprintf('Edit %d has an empty search block.', $index));
        }

        $matches = substr_count($contents, $search);

        if ($matches === 0) {
            throw new EditRejected(sprintf('The search block of edit %d was not found in "%s". Copy the lines exactly, including indentation.', $index, $edit->path));
        }

        if ($matches > 1) {
            throw new EditRejected(sprintf('The search block of edit %d matches %d places in "%s". Include more surrounding lines so it is unique.', $index, $matches, $edit->path));
        }

        $position = strpos($contents, $search);

        return substr_replace($contents, $replace, (int) $position, strlen($search));
    }
}
