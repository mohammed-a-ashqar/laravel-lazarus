<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Enums;

enum TestFramework: string
{
    case Pest = 'pest';
    case PhpUnit = 'phpunit';
    case Unknown = 'unknown';

    public static function detect(string $root): self
    {
        $composer = is_file($root.'/composer.json') ? (string) file_get_contents($root.'/composer.json') : '';

        if (is_file($root.'/tests/Pest.php') || str_contains($composer, '"pestphp/pest"')) {
            return self::Pest;
        }

        if (is_file($root.'/phpunit.xml') || is_file($root.'/phpunit.xml.dist') || str_contains($composer, '"phpunit/phpunit"')) {
            return self::PhpUnit;
        }

        return self::Unknown;
    }

    /**
     * The default command, relative to the worktree, before placeholders are expanded.
     *
     * @return list<string>
     */
    public function defaultCommand(): array
    {
        return match ($this) {
            self::Pest => ['{php}', '{vendor}/bin/pest', '--colors=never'],
            default => ['{php}', '{vendor}/bin/phpunit', '--colors=never'],
        };
    }
}
