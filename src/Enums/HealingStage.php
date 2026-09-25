<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Enums;

enum HealingStage: string
{
    case Preflight = 'preflight';
    case Reproduce = 'reproduce';
    case Patch = 'patch';
    case Verify = 'verify';
    case Diagnose = 'diagnose';
    case Publish = 'publish';

    public function label(): string
    {
        return match ($this) {
            self::Preflight => 'Preparing an isolated worktree',
            self::Reproduce => 'Writing a test that reproduces the bug',
            self::Patch => 'Proposing a minimal patch',
            self::Verify => 'Verifying the patch',
            self::Diagnose => 'Writing the diagnosis',
            self::Publish => 'Publishing the fix for review',
        };
    }
}
