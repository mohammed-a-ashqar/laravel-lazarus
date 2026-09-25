<?php

declare(strict_types=1);

use Alashqar\Lazarus\Capture\ExceptionCapturer;
use Alashqar\Lazarus\Enums\IncidentStatus;
use Alashqar\Lazarus\Events\FixVerified;
use Alashqar\Lazarus\Events\HealingFailed;
use Alashqar\Lazarus\Events\ReproductionConfirmed;
use Alashqar\Lazarus\Events\ReproductionFailed;
use Alashqar\Lazarus\Healing\Healer;
use Alashqar\Lazarus\Llm\Contracts\LlmDriver;
use Alashqar\Lazarus\Llm\Drivers\FakeDriver;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Redaction\Redactor;
use Alashqar\Lazarus\Support\Project;
use Alashqar\Lazarus\Tests\Support\FixtureRepository;
use Illuminate\Support\Facades\Event;

/*
 * These tests run the real pipeline against a real git repository: a real worktree, the
 * real Pest binary running real tests, a real commit and a real patch file. Only the model
 * is scripted, which is exactly the part a test must not depend on.
 */

const REPRODUCTION = [
    'path' => 'tests/Lazarus/ZeroQuantityInvoiceTest.php',
    'content' => <<<'PHP'
    <?php

    use App\InvoiceCalculator;

    it('prices an invoice whose lines all have a zero quantity', function () {
        expect((new InvoiceCalculator)->averageUnitPrice([['price' => 1200, 'quantity' => 0]]))->toBe(0.0);
    });
    PHP,
    'reasoning' => 'A free sample has quantity 0, so the average divides by zero.',
];

const FIX = [
    'summary' => 'Return zero when an invoice has no units',
    'edits' => [[
        'path' => 'app/InvoiceCalculator.php',
        'search' => "        return \$this->total(\$lines) / \$quantity;",
        'replace' => "        if (\$quantity === 0) {\n            return 0.0;\n        }\n\n        return \$this->total(\$lines) / \$quantity;",
    ]],
];

const VERDICT = [
    'root_cause' => 'averageUnitPrice() divides by the summed quantity, which is zero for free samples.',
    'explanation' => 'An invoice whose lines all have quantity 0 has no units to average over. Returning 0.0 matches how totals treat it.',
    'confidence' => 0.92,
];

function passingTest(string $name = 'NotReproducingTest'): array
{
    return ['path' => "tests/Lazarus/{$name}.php", 'content' => "<?php\n\nit('passes', fn () => expect(true)->toBeTrue());\n"];
}

beforeEach(function (): void {
    $this->repo = FixtureRepository::create();

    config()->set('lazarus.project_path', $this->repo->path);
    config()->set('lazarus.testing.vendor_path', dirname(__DIR__, 2).'/vendor');
    config()->set('lazarus.sandbox.worktrees_path', $this->repo->scratch.'/worktrees');
    config()->set('lazarus.publishers.patch.path', $this->repo->scratch.'/reports');
    config()->set('lazarus.publisher', 'patch');
    config()->set('lazarus.environments', ['testing']);

    foreach ([Project::class, Redactor::class, ExceptionCapturer::class] as $service) {
        $this->app->forgetInstance($service);
    }

    $this->llm = new FakeDriver;
    $this->app->instance(LlmDriver::class, $this->llm);

    $this->incident = app(ExceptionCapturer::class)->record($this->repo->triggerBug());
});

afterEach(function (): void {
    $this->repo->delete();
});

it('captures the real exception with its application context', function (): void {
    $incident = $this->incident;

    expect($incident->exception_class)->toBe(DivisionByZeroError::class)
        ->and($incident->message)->toBe('Division by zero')
        ->and($incident->location())->toBe('app/InvoiceCalculator.php:26')
        ->and($incident->status)->toBe(IncidentStatus::Captured)
        ->and($incident->incidentContext()->framework->value)->toBe('pest')
        ->and($incident->incidentContext()->gitSha)->toBe($this->repo->git('rev-parse', 'HEAD'))
        ->and($incident->incidentContext()->sources)->toHaveKey('app/InvoiceCalculator.php');
});

it('heals a real bug: red, patch, green, full suite, patch file, clean up', function (): void {
    Event::fake([ReproductionConfirmed::class, FixVerified::class, HealingFailed::class]);
    $this->llm->push(REPRODUCTION, FIX, VERDICT);
    $before = $this->repo->read('app/InvoiceCalculator.php');

    $report = app(Healer::class)->heal($this->incident);

    expect($report)->not->toBeNull((string) $this->incident->fresh()?->failure_reason);
    $incident = $this->incident->fresh();

    // Red, then green, then the whole suite.
    expect($report->red->passed())->toBeFalse()
        ->and($report->red->output)->toContain('DivisionByZeroError')
        ->and($report->green->passed())->toBeTrue()
        ->and($report->suite->passed())->toBeTrue()
        ->and($report->suite->output)->toContain('3 passed');

    // Published as a patch file and a report, and recorded on the incident.
    expect($incident->status)->toBe(IncidentStatus::Verified)
        ->and($incident->failure_reason)->toBeNull()
        ->and($incident->tokens_used)->toBe(3 * 1_250)
        ->and($incident->report_path)->toBeFile();

    $patch = (string) file_get_contents($incident->report_path);
    $markdown = (string) file_get_contents(str_replace('.patch', '.md', $incident->report_path));

    expect($patch)->toContain('Subject: [PATCH] fix: return zero when an invoice has no units')
        ->toContain('+        if ($quantity === 0) {')
        ->toContain('tests/Lazarus/ZeroQuantityInvoiceTest.php')
        ->and($markdown)->toContain(':red_circle:')->toContain('**Confidence:** 92%')->toContain('```diff');

    // Nothing leaked, nothing left behind, the main checkout untouched.
    $this->llm->assertNeverSent(FixtureRepository::SECRET);
    expect($this->repo->branches())->toBe(['main'])
        ->and($this->repo->worktreeCount())->toBe(1)
        ->and($this->repo->read('app/InvoiceCalculator.php'))->toBe($before)
        ->and($this->repo->git('status', '--porcelain'))->toBe('');

    // The patch is real: it applies to the main checkout with git am.
    $this->repo->git('-c', 'user.name=Reviewer', '-c', 'user.email=r@example.com', 'am', $incident->report_path);
    expect($this->repo->read('app/InvoiceCalculator.php'))->toContain('if ($quantity === 0)');

    Event::assertDispatched(ReproductionConfirmed::class, fn (ReproductionConfirmed $event): bool => $event->attempt === 1);
    Event::assertDispatched(FixVerified::class);
    Event::assertNotDispatched(HealingFailed::class);
});

it('rejects tests that do not reproduce the bug and publishes nothing', function (): void {
    Event::fake([ReproductionFailed::class, HealingFailed::class]);
    $this->llm->push(
        passingTest('FirstTest'),
        passingTest('SecondTest'),
        ['path' => 'tests/Lazarus/WrongReasonTest.php', 'content' => "<?php\n\nit('fails for another reason', fn () => expect(1)->toBe(2));\n"],
    );

    expect(app(Healer::class)->heal($this->incident))->toBeNull();

    $incident = $this->incident->fresh();
    expect($incident->status)->toBe(IncidentStatus::Failed)
        ->and($incident->failure_reason)->toContain('Could not reproduce the bug in 3 attempts')
        ->and($incident->failure_reason)->toContain('not with DivisionByZeroError')
        ->and($incident->report_path)->toBeNull()
        ->and($this->repo->scratch.'/reports')->not->toBeDirectory()
        ->and($this->repo->branches())->toBe(['main']);

    // Each rejection was explained to the model, with the real output.
    expect($this->llm->requests()[1]->lastUserMessage())->toContain('passed on the current code')
        ->and($this->llm->requests()[2]->lastUserMessage())->toContain('passed on the current code');

    Event::assertDispatched(ReproductionFailed::class);
    Event::assertDispatched(HealingFailed::class);
});

it('rejects a patch that does not fix the bug', function (): void {
    $cosmetic = ['summary' => 'Tidy the docblock', 'edits' => [[
        'path' => 'app/InvoiceCalculator.php',
        'search' => '     * The average price paid per unit, in cents.',
        'replace' => '     * The average price paid for one unit, in cents.',
    ]]];

    $this->llm->push(REPRODUCTION, $cosmetic, $cosmetic);

    expect(app(Healer::class)->heal($this->incident))->toBeNull();

    $incident = $this->incident->fresh();
    expect($incident->status)->toBe(IncidentStatus::Failed)
        ->and($incident->failure_reason)->toContain('No patch survived verification after 2 attempts')
        ->and($incident->failure_reason)->toContain('still fails')
        ->and($this->llm->requests()[2]->lastUserMessage())->toContain('DivisionByZeroError')
        ->and($this->repo->branches())->toBe(['main']);
});

it('rejects a patch that fixes the bug but breaks the suite', function (): void {
    $overreach = ['summary' => 'Always return zero', 'edits' => [[
        'path' => 'app/InvoiceCalculator.php',
        'search' => "        return \$this->total(\$lines) / \$quantity;",
        'replace' => '        return 0.0;',
    ]]];

    $this->llm->push(REPRODUCTION, $overreach, $overreach);

    expect(app(Healer::class)->heal($this->incident))->toBeNull()
        ->and($this->incident->fresh()->failure_reason)->toContain('full test suite now fails');
});

it('blocks a patch that touches .env and never retries it', function (): void {
    $this->llm->push(REPRODUCTION, ['summary' => 'Change the environment', 'edits' => [[
        'path' => '.env',
        'search' => 'APP_ENV=local',
        'replace' => 'APP_ENV=testing',
    ]]], FIX);

    expect(app(Healer::class)->heal($this->incident))->toBeNull();

    expect($this->incident->fresh()->failure_reason)->toContain('Blocked by the path guard')->toContain('".env*" files are never writable')
        ->and($this->llm->remaining())->toBe(1)
        ->and($this->repo->read('.env'))->toContain('APP_ENV=local');
});

it('blocks a patch that edits the tests instead of the code', function (): void {
    $this->llm->push(REPRODUCTION, ['summary' => 'Relax the test', 'edits' => [[
        'path' => 'tests/InvoiceCalculatorTest.php',
        'search' => '->toBe(1750.0);',
        'replace' => '->toBeFloat();',
    ]]]);

    expect(app(Healer::class)->heal($this->incident))->toBeNull()
        ->and($this->incident->fresh()->failure_reason)->toContain('A fix may not change tests');
});

it('refuses to heal from a dirty working tree without calling the model', function (): void {
    file_put_contents($this->repo->path.'/app/InvoiceCalculator.php', "<?php // local edit\n", FILE_APPEND);

    expect(app(Healer::class)->heal($this->incident))->toBeNull()
        ->and($this->incident->fresh()->failure_reason)->toContain('uncommitted changes');

    $this->llm->assertSent(0);
});
