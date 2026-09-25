<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Sandbox;

/**
 * Decides which files a model may create or edit.
 *
 * A path must match the configurable allowlist AND survive the hard denylist, which cannot
 * be configured away: secrets, dependencies, git internals, CI, schema and test harness
 * configuration are never writable, whatever the allowlist says.
 */
final readonly class PathGuard
{
    /** Any path segment starting with one of these is denied (".env", ".env.production", ".git"). */
    private const DENIED_SEGMENT_PREFIXES = ['.env', '.git'];

    private const DENIED_PREFIXES = [
        'vendor/', 'node_modules/', 'config/', 'database/migrations/', 'bootstrap/', 'storage/', 'public/',
        '.github/', '.gitlab/', '.circleci/',
    ];

    private const DENIED_FILES = [
        'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'artisan',
        'phpunit.xml', 'phpunit.xml.dist', 'phpstan.neon', 'phpstan.neon.dist', 'pest.php', 'tests/pest.php',
        'tests/testcase.php', 'tests/createsapplication.php', '.htaccess', 'dockerfile', 'docker-compose.yml',
    ];

    /** @var list<string> */
    private array $writable;

    /**
     * @param  list<string>  $writable  Allowed path prefixes such as "app/".
     */
    public function __construct(array $writable)
    {
        $this->writable = array_values(array_map(
            static fn (string $prefix): string => rtrim(strtolower(str_replace('\\', '/', $prefix)), '/').'/',
            $writable,
        ));
    }

    /**
     * @return string The normalised, project-relative path.
     *
     * @throws UnsafePath
     */
    public function assertWritable(string $path): string
    {
        $normalized = self::normalize($path);
        $reason = $this->violation($normalized);

        if ($reason !== null) {
            throw new UnsafePath(sprintf('Refusing to write "%s": %s', $path, $reason));
        }

        return $normalized;
    }

    public function allows(string $path): bool
    {
        return $this->violation(self::normalize($path)) === null;
    }

    private function violation(string $path): ?string
    {
        if ($path === '') {
            return 'the path is empty.';
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) || str_contains($path, "\0")) {
            return 'only project-relative paths are allowed.';
        }

        $segments = explode('/', $path);

        if (in_array('..', $segments, true)) {
            return 'the path leaves the project.';
        }

        $lower = strtolower($path);

        foreach ($segments as $segment) {
            foreach (self::DENIED_SEGMENT_PREFIXES as $prefix) {
                if (str_starts_with(strtolower($segment), $prefix)) {
                    return sprintf('"%s*" files are never writable.', $prefix);
                }
            }
        }

        foreach (self::DENIED_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return sprintf('"%s" is on the hard denylist.', $prefix);
            }
        }

        if (in_array($lower, self::DENIED_FILES, true)) {
            return 'this file is on the hard denylist.';
        }

        foreach ($this->writable as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return null;
            }
        }

        return sprintf('it is outside the writable paths (%s).', implode(', ', $this->writable));
    }

    private static function normalize(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = (string) preg_replace('#/+#', '/', $path);

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return $path;
    }
}
