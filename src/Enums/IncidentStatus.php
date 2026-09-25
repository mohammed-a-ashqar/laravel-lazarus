<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Enums;

enum IncidentStatus: string
{
    case Captured = 'captured';
    case Analyzing = 'analyzing';
    case Reproduced = 'reproduced';
    case Patched = 'patched';
    case Verified = 'verified';
    case PrOpened = 'pr_opened';
    case Failed = 'failed';
    case Ignored = 'ignored';

    /**
     * Whether a new occurrence may start (or restart) a heal on its own.
     */
    public function canAutoHeal(): bool
    {
        return in_array($this, [self::Captured, self::Failed], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Verified, self::PrOpened, self::Ignored], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PrOpened => 'PR opened',
            default => ucfirst($this->value),
        };
    }
}
