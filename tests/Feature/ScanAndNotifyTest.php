<?php

declare(strict_types=1);

use Alashqar\Lazarus\Capture\ExceptionCapturer;
use Alashqar\Lazarus\Capture\ExceptionSnapshot;
use Alashqar\Lazarus\Capture\LogParser;
use Alashqar\Lazarus\Enums\HealingStage;
use Alashqar\Lazarus\Events\HealingFailed;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Support\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/**
 * A log entry written the way Laravel writes it: JSON-escaped backslashes on Windows paths.
 */
function logEntry(string $root, string $time, string $class = 'DivisionByZeroError', string $message = 'Division by zero', string $file = 'app/Services/InvoiceCalculator.php', int $line = 22): string
{
    $escape = static fn (string $value): string => str_replace('\\', '\\\\', $value);
    $path = $escape(str_replace('/', '\\', $root.'/'.$file));
    $vendor = $escape(str_replace('/', '\\', $root.'/vendor/laravel/framework/src/Illuminate/Routing/Route.php'));

    return "[{$time}] production.ERROR: {$message} {\"exception\":\"[object] ({$escape($class)}(code: 0): {$message} at {$path}:{$line})\n"
        ."[stacktrace]\n"
        ."#0 {$vendor}(243): App\\\\Services\\\\InvoiceCalculator->averageUnitPrice(Array)\n"
        ."#1 [internal function]: Illuminate\\\\Routing\\\\Route->run()\n"
        ."#2 {main}\n"
        ."\"} \n";
}

function writeLog(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'lazarus-log').'.log';
    file_put_contents($path, $contents);

    return $path;
}

beforeEach(function (): void {
    $this->root = app(Project::class)->root;
});

it('parses Laravel log entries, including escaped Windows paths and the stack trace', function (): void {
    $log = writeLog("[2026-09-26 10:00:00] local.INFO: just information\n".logEntry($this->root, '2026-09-26 10:05:00'));

    $entries = iterator_to_array((new LogParser($this->root))->parse($log), false);

    expect($entries)->toHaveCount(1);
    $snapshot = $entries[0]['snapshot'];
    expect($snapshot->class)->toBe('DivisionByZeroError')
        ->and($snapshot->message)->toBe('Division by zero')
        ->and(str_replace('\\', '/', $snapshot->file))->toEndWith('app/Services/InvoiceCalculator.php')
        ->and($snapshot->line)->toBe(22)
        ->and($snapshot->frames[0]['class'])->toBe('App\\Services\\InvoiceCalculator')
        ->and($snapshot->frames[0]['function'])->toBe('averageUnitPrice')
        ->and($snapshot->frames)->toHaveCount(1)
        ->and($entries[0]['at']->format('Y-m-d H:i:s'))->toBe('2026-09-26 10:05:00');
});

it('maps paths from the server that wrote the log onto this project', function (): void {
    $log = writeLog(logEntry('/var/www/shop', '2026-09-26 10:05:00'));

    $snapshot = iterator_to_array((new LogParser($this->root))->parse($log), false)[0]['snapshot'];

    expect(str_replace('\\', '/', $snapshot->file))->toBe(str_replace('\\', '/', $this->root).'/app/Services/InvoiceCalculator.php');
});

it('scans logs into incidents, counts repeats and never counts a line twice', function (): void {
    Bus::fake();
    config()->set('lazarus.auto_heal', true);

    $log = writeLog(
        logEntry($this->root, '2026-09-26 10:00:00')
        .logEntry($this->root, '2026-09-26 11:00:00')
        .logEntry($this->root, '2026-09-26 12:00:00', 'Symfony\\Component\\Console\\Exception\\RuntimeException', 'The "--columns" option does not exist.', 'vendor/symfony/console/Input/ArgvInput.php', 223)
    );

    $this->artisan('lazarus:scan', ['paths' => [$log]])
        ->expectsOutputToContain('3 error(s) in 1 log file(s): 1 incident(s) updated, 1 new')
        ->expectsOutputToContain('Skipped (raised inside vendor code, or ignored)')
        ->assertSuccessful();

    $incident = Incident::query()->sole();
    expect($incident->occurrences)->toBe(2)
        ->and($incident->file)->toBe('app/Services/InvoiceCalculator.php')
        ->and($incident->first_seen_at->format('H:i'))->toBe('10:00')
        ->and($incident->last_seen_at->format('H:i'))->toBe('11:00');

    // Scanning is not a live error: it never starts a heal on its own.
    Bus::assertNothingDispatched();

    $this->artisan('lazarus:scan', ['paths' => [$log]])->expectsOutputToContain('Already counted')->assertSuccessful();
    expect($incident->fresh()?->occurrences)->toBe(2);
});

it('can preview a scan without storing anything', function (): void {
    $log = writeLog(logEntry($this->root, '2026-09-26 10:00:00'));

    $this->artisan('lazarus:scan', ['paths' => [$log], '--dry-run' => true])
        ->expectsOutputToContain('Nothing was stored')
        ->assertSuccessful();

    expect(Incident::query()->count())->toBe(0);
});

it('only scans errors newer than --since', function (): void {
    $log = writeLog(logEntry($this->root, now()->subDays(10)->format('Y-m-d H:i:s')).logEntry($this->root, now()->subHour()->format('Y-m-d H:i:s'), message: 'Recent'));

    $this->artisan('lazarus:scan', ['paths' => [$log], '--since' => '7d'])->assertSuccessful();

    expect(Incident::query()->pluck('message')->all())->toBe(['Recent']);
    $this->artisan('lazarus:scan', ['paths' => [$log], '--since' => 'not a date'])->assertFailed();
});

it('notifies Telegram and a webhook about a new live error, but not about scanned ones', function (): void {
    Http::fake();
    config()->set('lazarus.notifications.telegram', ['token' => '123:secret', 'chat_id' => '42']);
    config()->set('lazarus.notifications.webhook', 'https://hooks.slack.test/abc');

    app(ExceptionCapturer::class)->record(ExceptionSnapshot::fromThrowable(new DivisionByZeroError('Division by zero')));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot123:secret/sendMessage'
        && $request['chat_id'] === '42'
        && str_contains($request['text'], 'New error')
        && str_contains($request['text'], 'DivisionByZeroError: Division by zero'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hooks.slack.test/abc'
        && str_contains($request['text'], 'New error') && $request['content'] !== '');
    Http::assertSentCount(2);

    app(ExceptionCapturer::class)->record(ExceptionSnapshot::fromThrowable(new TypeError('From the log')), null, now(), fromLog: true);
    Http::assertSentCount(2);
});

it('emails a failed heal and keeps working when a channel is down', function (): void {
    Http::fake(['*' => Http::response('down', 500)]);
    config()->set('lazarus.notifications.mail', 'dev@example.com, not-an-email');
    config()->set('lazarus.notifications.webhook', 'https://hooks.slack.test/abc');
    config()->set('mail.default', 'array');

    $incident = Incident::query()->create([
        'fingerprint' => str_repeat('a', 64), 'exception_class' => 'TypeError', 'message' => 'Bad argument',
        'file' => 'app/X.php', 'line' => 3, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    event(new HealingFailed($incident, 'Could not reproduce the bug in 3 attempts.', HealingStage::Reproduce));

    $sent = app('mailer')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getOriginalMessage()->getSubject())->toContain('Could not fix automatically')
        ->and($sent[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe('dev@example.com');
});

it('sends a test notification and reports each channel', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 401)]);
    config()->set('lazarus.notifications.telegram', ['token' => '123:secret', 'chat_id' => '42']);

    $this->artisan('lazarus:notify-test')
        ->expectsOutputToContain('FAILED')
        ->doesntExpectOutputToContain('123:secret')
        ->assertFailed();

    config()->set('lazarus.notifications.telegram', ['token' => null, 'chat_id' => null]);
    $this->artisan('lazarus:notify-test')->expectsOutputToContain('No notification channel')->assertFailed();
});

it('skips errors whose only application frame is a front controller or an off-limits file', function (): void {
    $log = writeLog(
        logEntry($this->root, '2026-09-26 10:00:00', 'RuntimeException', 'The "--columns" option does not exist.', 'artisan', 13)
        .logEntry($this->root, '2026-09-26 10:01:00', 'Illuminate\Database\QueryException', 'Table already exists', 'database/migrations/2026_01_01_000000_create_posts_table.php', 12)
    );

    $this->artisan('lazarus:scan', ['paths' => [$log]])
        ->expectsOutputToContain('0 incident(s) updated, 0 new')
        ->expectsOutputToContain('Skipped (in files Lazarus may not change, such as migrations)')
        ->assertSuccessful();

    expect(Incident::query()->count())->toBe(0);
});
