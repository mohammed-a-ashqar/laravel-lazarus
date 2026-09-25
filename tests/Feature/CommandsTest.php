<?php

declare(strict_types=1);

use Alashqar\Lazarus\Enums\IncidentStatus;
use Alashqar\Lazarus\Jobs\HealIncident;
use Alashqar\Lazarus\Models\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

function incident(array $attributes = []): Incident
{
    return Incident::query()->create([
        'fingerprint' => hash('sha256', (string) random_int(0, PHP_INT_MAX)),
        'exception_class' => DivisionByZeroError::class,
        'message' => 'Division by zero',
        'file' => 'app/InvoiceCalculator.php',
        'line' => 26,
        'first_seen_at' => CarbonImmutable::now()->subHour(),
        'last_seen_at' => CarbonImmutable::now(),
        ...$attributes,
    ]);
}

it('lists incidents with their status and outcome', function (): void {
    incident(['occurrences' => 4]);
    incident(['exception_class' => TypeError::class, 'message' => 'Argument #1 must be of type int', 'status' => IncidentStatus::Failed, 'failure_reason' => 'Could not reproduce the bug']);

    $this->artisan('lazarus:list')
        ->expectsOutputToContain('DivisionByZeroError: Division by zero')
        ->expectsOutputToContain('Could not reproduce the bug')
        ->assertSuccessful();
});

it('filters the list by status', function (): void {
    incident(['message' => 'first', 'status' => IncidentStatus::Failed]);
    incident(['message' => 'second']);

    $this->artisan('lazarus:list', ['--status' => 'failed'])
        ->expectsOutputToContain('first')
        ->doesntExpectOutputToContain('second')
        ->assertSuccessful();

    $this->artisan('lazarus:list', ['--status' => 'bogus'])->assertFailed();
});

it('ignores an incident by id or fingerprint prefix', function (): void {
    $incident = incident();

    $this->artisan('lazarus:ignore', ['incident' => substr($incident->fingerprint, 0, 8)])->assertSuccessful();

    expect($incident->fresh()->status)->toBe(IncidentStatus::Ignored);

    $this->artisan('lazarus:ignore', ['incident' => '999'])->assertFailed();
});

it('refuses to heal an ignored incident', function (): void {
    $incident = incident(['status' => IncidentStatus::Ignored]);

    $this->artisan('lazarus:heal', ['incident' => (string) $incident->id])
        ->expectsOutputToContain('is ignored')
        ->assertFailed();
});

it('queues a heal on request', function (): void {
    Bus::fake();
    $incident = incident();

    $this->artisan('lazarus:heal', ['incident' => (string) $incident->id, '--queue' => true])->assertSuccessful();

    Bus::assertDispatched(HealIncident::class, fn (HealIncident $job): bool => $job->incidentId === $incident->id);
});

it('explains why a heal failed and publishes nothing', function (): void {
    config()->set('lazarus.project_path', sys_get_temp_dir().'/lazarus-missing-'.uniqid());
    $incident = incident();

    $this->artisan('lazarus:heal', ['incident' => (string) $incident->id])
        ->expectsOutputToContain('is healing incident #'.$incident->id)
        ->expectsOutputToContain('Nothing was published')
        ->assertFailed();

    expect($incident->fresh()->status)->toBe(IncidentStatus::Failed);
});

it('runs the doctor checks', function (): void {
    config()->set('lazarus.llm.driver', 'anthropic');
    config()->set('lazarus.llm.drivers.anthropic.api_key', null);

    $this->artisan('lazarus:doctor')
        ->expectsOutputToContain('git is installed')
        ->expectsOutputToContain('lazarus_incidents table exists')
        ->expectsOutputToContain('LLM driver anthropic (claude-sonnet-5) is configured')
        ->expectsOutputToContain('Daily budget has room')
        ->assertFailed();
});
