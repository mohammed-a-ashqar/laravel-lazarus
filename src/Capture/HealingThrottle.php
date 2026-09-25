<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Capture;

use Alashqar\Lazarus\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Limits automatic heals: one per fingerprint per cooldown window, and a daily maximum.
 */
final readonly class HealingThrottle
{
    public function __construct(
        private Repository $cache,
        private Settings $settings,
    ) {}

    public function allows(string $fingerprint): bool
    {
        if ($this->cache->has($this->cooldownKey($fingerprint))) {
            return false;
        }

        return $this->healsToday() < $this->settings->int('max_heals_per_day', 10);
    }

    public function hit(string $fingerprint): void
    {
        $minutes = max(1, $this->settings->int('cooldown_minutes', 60));

        $this->cache->put($this->cooldownKey($fingerprint), true, CarbonImmutable::now()->addMinutes($minutes));
        $this->cache->add($this->dailyKey(), 0, CarbonImmutable::now()->endOfDay());
        $this->cache->increment($this->dailyKey());
    }

    public function healsToday(): int
    {
        $count = $this->cache->get($this->dailyKey(), 0);

        return is_numeric($count) ? (int) $count : 0;
    }

    private function cooldownKey(string $fingerprint): string
    {
        return 'lazarus:cooldown:'.$fingerprint;
    }

    private function dailyKey(): string
    {
        return 'lazarus:heals:'.CarbonImmutable::now()->format('Y-m-d');
    }
}
