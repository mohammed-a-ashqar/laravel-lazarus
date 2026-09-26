<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Capture;

use Alashqar\Lazarus\Support\Path;
use Carbon\CarbonImmutable;
use Generator;
use Throwable;

/**
 * Reads exceptions back out of Laravel log files, so errors that already happened can become
 * incidents without anyone reproducing them by hand.
 *
 * Entries look like `[2026-09-26 10:00:00] production.ERROR: message {"exception":"[object]
 * (Class(code: 0): message at /path/File.php:12)` followed by a `[stacktrace]` block.
 */
final class LogParser
{
    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] [\w-]+\.([A-Z]+): /';

    private const EXCEPTION = '/\{"exception":"\[object\] \(([\w\\\\\/]+)\(code: [^)]*\): (.*?) at (.+?):(\d+)\)\s*$/m';

    private const FRAME = '/^#\d+ (.+?)\((\d+)\): (?:([\w\\\\\/]+)(?:->|::))?([\w{}]+)\(/';

    public function __construct(private readonly string $projectRoot) {}

    /**
     * @return Generator<int, array{at: CarbonImmutable, snapshot: ExceptionSnapshot}>
     */
    public function parse(string $path, ?CarbonImmutable $since = null): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            $entry = null;

            while (($line = fgets($handle)) !== false) {
                if (preg_match(self::HEADER, $line) === 1) {
                    if ($entry !== null && ($parsed = $this->entry($entry, $since)) !== null) {
                        yield $parsed;
                    }

                    $entry = $line;
                } elseif ($entry !== null) {
                    $entry .= $line;
                }
            }

            if ($entry !== null && ($parsed = $this->entry($entry, $since)) !== null) {
                yield $parsed;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{at: CarbonImmutable, snapshot: ExceptionSnapshot}|null
     */
    public function entry(string $entry, ?CarbonImmutable $since = null): ?array
    {
        if (preg_match(self::HEADER, $entry, $header) !== 1 || ! in_array($header[2], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($header[1]);
        } catch (Throwable) {
            return null;
        }

        if ($since !== null && $at->lessThan($since)) {
            return null;
        }

        // Only the outermost exception: a "[previous exception]" block follows the stack trace.
        $entry = explode('[previous exception]', $entry, 2)[0];

        if (preg_match(self::EXCEPTION, $entry, $exception) !== 1) {
            return null;
        }

        $frames = [];
        $stackStart = strpos($entry, '[stacktrace]');

        if ($stackStart !== false) {
            foreach (preg_split('/\R/', substr($entry, $stackStart)) ?: [] as $line) {
                if (preg_match(self::FRAME, $line, $frame) !== 1) {
                    continue;
                }

                $frames[] = [
                    'file' => str_starts_with($frame[1], '[') ? null : self::unescape($frame[1]),
                    'line' => (int) $frame[2],
                    'class' => $frame[3] === '' ? null : self::className($frame[3]),
                    'function' => $frame[4],
                ];
            }
        }

        $file = self::unescape($exception[3]);
        $root = $this->loggedRoot($file, $frames);

        if ($root !== null) {
            $file = $this->rebase($file, $root);

            foreach ($frames as $index => $frame) {
                if ($frame['file'] !== null) {
                    $frames[$index]['file'] = $this->rebase($frame['file'], $root);
                }
            }
        }

        return [
            'at' => $at,
            'snapshot' => new ExceptionSnapshot(
                self::className($exception[1]),
                self::unescape($exception[2]),
                $file,
                (int) $exception[4],
                $frames,
            ),
        ];
    }

    /**
     * The project root on the machine that wrote the log (a production server, say), found from
     * the first vendor path. Null when it is this project already or cannot be told.
     *
     * @param  list<array{file: string|null, line: int|null, class: string|null, function: string|null}>  $frames
     */
    private function loggedRoot(string $file, array $frames): ?string
    {
        $projectRoot = strtolower(Path::normalize($this->projectRoot).'/');

        if (str_starts_with(strtolower(Path::normalize($file)), $projectRoot)) {
            return null;
        }

        foreach ([$file, ...array_column($frames, 'file')] as $path) {
            if (! is_string($path)) {
                continue;
            }

            $normalized = Path::normalize($path);
            $vendor = strpos($normalized, '/vendor/');

            if ($vendor !== false) {
                $root = substr($normalized, 0, $vendor);

                return strcasecmp($root, Path::normalize($this->projectRoot)) === 0 ? null : $root;
            }
        }

        return null;
    }

    private function rebase(string $path, string $root): string
    {
        $normalized = Path::normalize($path);

        return str_starts_with(strtolower($normalized), strtolower($root.'/'))
            ? Path::normalize($this->projectRoot).substr($normalized, strlen($root))
            : $path;
    }

    /**
     * Log context is JSON-encoded, so backslashes arrive doubled.
     */
    private static function unescape(string $value): string
    {
        return str_replace(['\\\\', '\\"', '\\/'], ['\\', '"', '/'], $value);
    }

    private static function className(string $class): string
    {
        return ltrim(str_replace('/', '\\', self::unescape($class)), '\\');
    }
}
