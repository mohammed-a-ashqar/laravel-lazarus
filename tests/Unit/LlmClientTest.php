<?php

declare(strict_types=1);

use Alashqar\Lazarus\Healing\Data\Diagnosis;
use Alashqar\Lazarus\Healing\Data\Patch;
use Alashqar\Lazarus\Healing\Data\ReproductionTest;
use Alashqar\Lazarus\Llm\BudgetExceeded;
use Alashqar\Lazarus\Llm\Conversation;
use Alashqar\Lazarus\Llm\Drivers\FakeDriver;
use Alashqar\Lazarus\Llm\InvalidLlmResponse;
use Alashqar\Lazarus\Llm\LlmClient;
use Alashqar\Lazarus\Llm\TokenBudget;
use Alashqar\Lazarus\Redaction\Redactor;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function llmClient(FakeDriver $driver, ?TokenBudget $budget = null): LlmClient
{
    return new LlmClient(
        $driver,
        new Redactor(['password', 'token'], ['super-secret-db-password']),
        $budget ?? new TokenBudget(new Repository(new ArrayStore), 0, 0),
        maxInvalidResponses: 2,
    );
}

const DIAGNOSIS = ['root_cause' => 'Division by a zero quantity', 'explanation' => 'Empty invoices have no quantity.', 'confidence' => 0.9];

it('decodes JSON wrapped in Markdown fences or surrounded by chatter', function (string $content): void {
    expect(LlmClient::decode($content))->toBe(['ok' => true]);
})->with([
    'plain' => '{"ok": true}',
    'fenced' => "```json\n{\"ok\": true}\n```",
    'chatter' => "Sure! Here it is:\n{\"ok\": true}\nLet me know.",
]);

it('rejects answers that are not a JSON object', function (string $content, string $problem): void {
    expect(fn () => LlmClient::decode($content))->toThrow(InvalidLlmResponse::class, $problem);
})->with([
    ['I cannot help with that.', 'did not contain a JSON object'],
    ['{"ok": tru}', 'was not valid JSON'],
]);

it('validates the shape of each response DTO', function (string $class, array $data, string $problem): void {
    expect(fn () => $class::fromLlm($data))->toThrow(InvalidLlmResponse::class, $problem);
})->with([
    'test outside tests/' => [ReproductionTest::class, ['path' => 'app/FooTest.php', 'content' => '<?php'], 'must be a new file under tests/'],
    'test not php' => [ReproductionTest::class, ['path' => 'tests/FooTest.php', 'content' => 'it("works")'], 'must be a complete PHP file'],
    'test missing content' => [ReproductionTest::class, ['path' => 'tests/FooTest.php'], 'field "content" must be a non-empty string'],
    'patch without edits' => [Patch::class, ['summary' => 'x', 'edits' => []], 'field "edits" must be a non-empty array'],
    'patch edit without search' => [Patch::class, ['summary' => 'x', 'edits' => [['path' => 'app/A.php', 'replace' => 'y']]], 'field "search"'],
    'patch no-op edit' => [Patch::class, ['summary' => 'x', 'edits' => [['path' => 'app/A.php', 'search' => 'y', 'replace' => 'y']]], 'does not change anything'],
    'confidence out of range' => [Diagnosis::class, [...DIAGNOSIS, 'confidence' => 7], 'between 0 and 1'],
    'confidence as string' => [Diagnosis::class, [...DIAGNOSIS, 'confidence' => 'high'], 'between 0 and 1'],
]);

it('returns a typed DTO for a valid answer', function (): void {
    $diagnosis = llmClient(new FakeDriver([DIAGNOSIS]))->ask(new Conversation('system'), Diagnosis::class);

    expect($diagnosis)->toBeInstanceOf(Diagnosis::class)
        ->and($diagnosis->rootCause)->toBe('Division by a zero quantity')
        ->and($diagnosis->confidencePercent())->toBe(90);
});

it('feeds the validation error back to the model and retries', function (): void {
    $driver = new FakeDriver(['not json at all', [...DIAGNOSIS, 'confidence' => 2], DIAGNOSIS]);
    $conversation = (new Conversation('system'))->user('diagnose');

    llmClient($driver)->ask($conversation, Diagnosis::class);

    $driver->assertSent(3);
    $feedback = $driver->requests()[1]->lastUserMessage();

    expect($feedback)->toContain('did not contain a JSON object')->toContain('"root_cause"')
        ->and($driver->requests()[2]->lastUserMessage())->toContain('between 0 and 1')
        ->and($conversation->usage()->totalTokens())->toBe(3 * 1_250);
});

it('gives up after the configured number of invalid answers', function (): void {
    $driver = new FakeDriver(['nope', 'still nope', 'nope again', DIAGNOSIS]);

    expect(fn () => llmClient($driver)->ask(new Conversation('system'), Diagnosis::class))
        ->toThrow(InvalidLlmResponse::class, 'invalid output 3 times');

    expect($driver->remaining())->toBe(1);
});

it('redacts every message before it leaves the application', function (): void {
    $driver = new FakeDriver([DIAGNOSIS]);
    $conversation = (new Conversation('Connect with super-secret-db-password'))
        ->user("Failing request: {\"password\": \"hunter22\"} from ada@example.com with Bearer abc.def.ghi");

    llmClient($driver)->ask($conversation, Diagnosis::class);

    $driver->assertNeverSent('super-secret-db-password');
    $driver->assertNeverSent('hunter22');
    $driver->assertNeverSent('ada@example.com');
    $driver->assertNeverSent('Bearer abc.def.ghi');
});

it('checks the budget before every call and records what was spent', function (): void {
    $cache = new Repository(new ArrayStore);
    $budget = new TokenBudget($cache, dailyTokens: 2_000, dailyCost: 0);
    $driver = new FakeDriver([DIAGNOSIS, DIAGNOSIS], tokensPerCall: 1_000);

    llmClient($driver, $budget)->ask(new Conversation('s'), Diagnosis::class);

    expect($budget->tokensUsedToday())->toBe(1_250);

    llmClient($driver, $budget)->ask(new Conversation('s'), Diagnosis::class);

    expect(fn () => llmClient($driver, $budget)->ask(new Conversation('s'), Diagnosis::class))->toThrow(BudgetExceeded::class);
    $driver->assertSent(2);
});
