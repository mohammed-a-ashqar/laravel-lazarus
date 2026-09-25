<?php

declare(strict_types=1);

use Alashqar\Lazarus\Capture\ExceptionCapturer;
use Alashqar\Lazarus\Capture\HealingThrottle;
use Alashqar\Lazarus\Enums\IncidentStatus;
use Alashqar\Lazarus\Events\IncidentCaptured;
use Alashqar\Lazarus\Jobs\HealIncident;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Support\Project;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    // Treat this package's own tests/ directory as "the application" for these tests.
    config()->set('lazarus.project_path', dirname(__DIR__, 2));
    $this->app->forgetInstance(Project::class);
    $this->app->forgetInstance(ExceptionCapturer::class);
});

function brokenCheckout(int $orderId): never
{
    throw new RuntimeException("Order {$orderId} has no shipping address");
}

function reportThrown(int $orderId = 1001): void
{
    try {
        brokenCheckout($orderId);
    } catch (RuntimeException $exception) {
        app(ExceptionHandler::class)->report($exception);
    }
}

it('records reported exceptions through the exception handler', function (): void {
    Event::fake([IncidentCaptured::class]);

    reportThrown();

    $incident = Incident::query()->sole();

    expect($incident->exception_class)->toBe(RuntimeException::class)
        ->and($incident->message)->toBe('Order 1001 has no shipping address')
        ->and($incident->file)->toBe('tests/Feature/CaptureTest.php')
        ->and($incident->status)->toBe(IncidentStatus::Captured)
        ->and($incident->occurrences)->toBe(1)
        ->and($incident->incidentContext()->frames[0]->snippet)->toContain('has no shipping address');

    Event::assertDispatched(IncidentCaptured::class, fn (IncidentCaptured $event): bool => $event->isNew);
});

it('groups repeated occurrences of the same bug', function (): void {
    reportThrown(1001);
    reportThrown(2002);
    reportThrown(3003);

    expect(Incident::query()->count())->toBe(1)
        ->and(Incident::query()->sole()->occurrences)->toBe(3);
});

it('counts an exception object reported twice only once', function (): void {
    $exception = new RuntimeException('Reported by two handlers');

    app(ExceptionHandler::class)->report($exception);
    app(ExceptionCapturer::class)->report($exception);

    expect(Incident::query()->sole()->occurrences)->toBe(1);
});

it('skips ignored exception classes and other environments', function (): void {
    app(ExceptionHandler::class)->report(ValidationException::withMessages(['email' => 'Invalid.']));

    config()->set('lazarus.environments', ['production']);
    reportThrown();

    expect(Incident::query()->count())->toBe(0);
});

it('can be switched off', function (): void {
    config()->set('lazarus.enabled', false);

    reportThrown();

    expect(Incident::query()->count())->toBe(0);
});

it('stores the route and redacted input of the failing request', function (): void {
    Route::post('/checkout', function (): never {
        brokenCheckout(42);
    })->name('checkout');

    $this->postJson('/checkout', ['email' => 'ada@example.com', 'password' => 'hunter22', 'card' => '4242 4242 4242 4242', 'qty' => 2]);

    $context = Incident::query()->sole()->incidentContext();

    expect($context->method)->toBe('POST')
        ->and($context->route)->toContain('/checkout [checkout]')
        ->and($context->input)->toBe(['email' => '[EMAIL]', 'password' => '[REDACTED]', 'card' => '[CARD]', 'qty' => 2]);
});

it('queues a heal for new incidents when auto-heal is on, once per cooldown', function (): void {
    Bus::fake();
    config()->set('lazarus.auto_heal', true);

    reportThrown();
    reportThrown();

    Bus::assertDispatchedTimes(HealIncident::class, 1);
    Bus::assertDispatched(HealIncident::class, fn (HealIncident $job): bool => $job->uniqueId() === Incident::query()->sole()->fingerprint);
});

it('respects the daily heal limit', function (): void {
    Bus::fake();
    config()->set('lazarus.auto_heal', true);
    config()->set('lazarus.max_heals_per_day', 1);

    app(HealingThrottle::class)->hit('someone-else');
    reportThrown();

    Bus::assertNotDispatched(HealIncident::class);
});

it('never queues a heal for ignored incidents', function (): void {
    Bus::fake();
    reportThrown();
    Incident::query()->sole()->transitionTo(IncidentStatus::Ignored);
    config()->set('lazarus.auto_heal', true);

    reportThrown();

    Bus::assertNotDispatched(HealIncident::class);
    expect(Incident::query()->sole()->occurrences)->toBe(2);
});

it('never lets a failure inside Lazarus break the application', function (): void {
    Schema::drop('lazarus_incidents');

    expect(app(ExceptionCapturer::class)->report(new RuntimeException('boom')))->toBeNull();
});
