<?php

declare(strict_types=1);

use Alashqar\Lazarus\Llm\BudgetExceeded;
use Alashqar\Lazarus\Llm\Data\Usage;
use Alashqar\Lazarus\Llm\TokenBudget;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function dailyBudget(int $tokens = 10_000, float $cost = 1.00, ?Repository $cache = null): TokenBudget
{
    return new TokenBudget($cache ?? new Repository(new ArrayStore), $tokens, $cost);
}

it('allows calls while under both limits', function (): void {
    $budget = dailyBudget();
    $budget->record(new Usage(4_000, 1_000, 0.25));

    $budget->ensureAvailable();

    expect($budget->tokensUsedToday())->toBe(5_000)
        ->and($budget->costToday())->toBe(0.25)
        ->and($budget->remainingTokens())->toBe(5_000)
        ->and($budget->remainingCost())->toBe(0.75);
});

it('refuses the next call once the token limit is reached', function (): void {
    $budget = dailyBudget(tokens: 5_000);
    $budget->record(new Usage(4_000, 1_000));

    $budget->ensureAvailable();
})->throws(BudgetExceeded::class, 'Daily token budget of 5,000 tokens is used up.');

it('refuses the next call once the cost limit is reached', function (): void {
    $budget = dailyBudget(cost: 0.50);
    $budget->record(new Usage(10, 10, 0.30));
    $budget->record(new Usage(10, 10, 0.21));

    $budget->ensureAvailable();
})->throws(BudgetExceeded::class, 'Daily cost budget of $0.50 is used up.');

it('treats a zero limit as unlimited', function (): void {
    $budget = dailyBudget(tokens: 0, cost: 0);
    $budget->record(new Usage(10_000_000, 10_000_000, 999));

    $budget->ensureAvailable();

    expect($budget->remainingTokens())->toBeNull()->and($budget->remainingCost())->toBeNull();
});

it('starts again the next day', function (): void {
    $cache = new Repository(new ArrayStore);
    CarbonImmutable::setTestNow('2026-03-01 23:00:00');
    dailyBudget(tokens: 100, cache: $cache)->record(new Usage(100, 0));

    CarbonImmutable::setTestNow('2026-03-02 08:00:00');

    expect(dailyBudget(tokens: 100, cache: $cache)->tokensUsedToday())->toBe(0);

    CarbonImmutable::setTestNow();
});

it('prices usage per million tokens', function (): void {
    $usage = Usage::priced(1_000_000, 100_000, ['input' => 2.00, 'output' => 10.00]);

    expect($usage->cost)->toBe(3.0)->and($usage->totalTokens())->toBe(1_100_000);
});
