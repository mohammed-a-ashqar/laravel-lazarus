<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing\Data;

/**
 * Replace one exact, unique block of an existing file.
 */
final readonly class FileEdit
{
    public function __construct(
        public string $path,
        public string $search,
        public string $replace,
    ) {}
}
