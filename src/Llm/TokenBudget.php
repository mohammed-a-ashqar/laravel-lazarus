<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm;

use Alashqar\Lazarus\Llm\Data\Usage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * A hard daily ceiling on tokens and money spent across every heal.
 *
 * Checked before each call, recorded after it. A single call can overshoot the limit
 * (usage is only known once it returns), but no call starts once the limit is reached.
 */
final readonly class TokenBudget
{
    public function __construct(
        private Repository $cache,
        private int $dailyTokens,
        private float $dailyCost,
    ) {}

    /**
     * @throws BudgetExceeded
     */
    public function ensureAvailable(): void
    {
        if ($this->dailyTokens > 0 && $this->tokensUsedToday() >= $this->dailyTokens) {
            throw new BudgetExceeded(sprintf('Daily token budget of %s tokens is used up.', number_format($this->dailyTokens)));
        }

        if ($this->dailyCost > 0 && $this->costToday() >= $this->dailyCost) {
            throw new BudgetExceeded(sprintf('Daily cost budget of $%.2f is used up.', $this->dailyCost));
        }
    }

    public function record(Usage $usage): void
    {
        $expires = CarbonImmutable::now()->endOfDay()->addHour();

        $this->cache->add($this->key('tokens'), 0, $expires);
        $this->cache->increment($this->key('tokens'), $usage->totalTokens());

        // Stored in micro-dollars so the cache can increment an integer atomically.
        $this->cache->add($this->key('cost'), 0, $expires);
        $this->cache->increment($this->key('cost'), (int) round($usage->cost * 1_000_000));
    }

    public function tokensUsedToday(): int
    {
        $value = $this->cache->get($this->key('tokens'), 0);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function costToday(): float
    {
        $value = $this->cache->get($this->key('cost'), 0);

        return is_numeric($value) ? ((int) $value) / 1_000_000 : 0.0;
    }

    public function remainingTokens(): ?int
    {
        return $this->dailyTokens > 0 ? max(0, $this->dailyTokens - $this->tokensUsedToday()) : null;
    }

    public function remainingCost(): ?float
    {
        return $this->dailyCost > 0 ? max(0.0, $this->dailyCost - $this->costToday()) : null;
    }

    private function key(string $metric): string
    {
        return 'lazarus:budget:'.$metric.':'.CarbonImmutable::now()->format('Y-m-d');
    }
}
