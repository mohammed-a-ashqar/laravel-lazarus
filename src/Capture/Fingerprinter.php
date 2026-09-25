<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Capture;

use Alashqar\Lazarus\Support\Project;

/**
 * Groups occurrences of the same bug: same exception class, thrown from the same line of
 * application code, with the same message once volatile values are stripped out.
 */
final readonly class Fingerprinter
{
    public function __construct(private Project $project) {}

    public function fingerprint(ExceptionSnapshot $exception): string
    {
        [$file, $line] = $this->origin($exception);

        return hash('sha256', implode('|', [
            $exception->class,
            $file ?? '-',
            (string) ($line ?? 0),
            self::normalizeMessage($exception->message),
        ]));
    }

    /**
     * The innermost frame that belongs to the application, falling back to the throw site.
     *
     * @return array{0: string|null, 1: int|null}
     */
    public function origin(ExceptionSnapshot $exception): array
    {
        foreach ($exception->callStack() as $frame) {
            if ($frame['file'] === null) {
                continue;
            }

            $relative = $this->project->relative($frame['file']);

            if ($relative !== null) {
                return [$relative, $frame['line']];
            }
        }

        return [null, null];
    }

    public static function normalizeMessage(string $message): string
    {
        $patterns = [
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
            '/\b[0-9A-HJKMNP-TV-Z]{26}\b/' => '{ulid}',
            '/\b[0-9a-f]{16,}\b/i' => '{hash}',
            '/\b\d+(?:\.\d+)?\b/' => '{n}',
            '/\s+/' => ' ',
        ];

        return trim((string) preg_replace(array_keys($patterns), array_values($patterns), $message));
    }
}
