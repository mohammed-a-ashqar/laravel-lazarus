<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Support;

/**
 * The application being healed: where it lives and which of its files count as "your code".
 */
final readonly class Project
{
    public string $root;

    public string $vendorPath;

    public function __construct(string $root, ?string $vendorPath = null)
    {
        $this->root = Path::normalize(rtrim($root, '\\/'));
        $this->vendorPath = Path::normalize(rtrim($vendorPath ?? $this->root.'/vendor', '\\/'));
    }

    /**
     * The project-relative path of an application file, or null for vendor and foreign files.
     */
    public function relative(string $file): ?string
    {
        $file = Path::normalize($file);
        $prefix = $this->root.'/';

        if (! str_starts_with(strtolower($file), strtolower($prefix))) {
            return null;
        }

        $relative = substr($file, strlen($prefix));

        foreach (['vendor/', 'storage/framework/', 'bootstrap/cache/', 'node_modules/'] as $excluded) {
            if (str_starts_with($relative, $excluded)) {
                return null;
            }
        }

        return $relative;
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->root : $this->root.'/'.ltrim($relative, '/');
    }
}
