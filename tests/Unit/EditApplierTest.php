<?php

declare(strict_types=1);

use Alashqar\Lazarus\Healing\Data\FileEdit;
use Alashqar\Lazarus\Healing\EditApplier;
use Alashqar\Lazarus\Healing\EditRejected;

const CALCULATOR = <<<'PHP'
<?php

final class InvoiceCalculator
{
    public function average(int $total, int $quantity): float
    {
        return $total / $quantity;
    }
}
PHP;

function fileReader(array $files): Closure
{
    return static fn (string $path): ?string => $files[$path] ?? null;
}

it('replaces a block that matches exactly once', function (): void {
    $planned = (new EditApplier)->plan([
        new FileEdit('app/InvoiceCalculator.php', "        return \$total / \$quantity;", "        return \$quantity === 0 ? 0.0 : \$total / \$quantity;"),
    ], fileReader(['app/InvoiceCalculator.php' => CALCULATOR]));

    expect($planned['app/InvoiceCalculator.php'])
        ->toContain('return $quantity === 0 ? 0.0 : $total / $quantity;')
        ->not->toContain('        return $total / $quantity;');
});

it('applies several edits to the same file in order', function (): void {
    $planned = (new EditApplier)->plan([
        new FileEdit('a.php', 'int $total', 'int $totalCents'),
        new FileEdit('a.php', 'return $total /', 'return $totalCents /'),
    ], fileReader(['a.php' => CALCULATOR]));

    expect($planned['a.php'])->toContain('int $totalCents')->toContain('return $totalCents / $quantity;');
});

it('rejects a search block that is not in the file', function (): void {
    (new EditApplier)->plan([new FileEdit('a.php', 'return $total * $quantity;', 'x')], fileReader(['a.php' => CALCULATOR]));
})->throws(EditRejected::class, 'was not found');

it('rejects an ambiguous search block', function (): void {
    (new EditApplier)->plan([new FileEdit('a.php', '$quantity', '$qty')], fileReader(['a.php' => CALCULATOR]));
})->throws(EditRejected::class, 'matches 2 places');

it('rejects edits to files that do not exist', function (): void {
    (new EditApplier)->plan([new FileEdit('app/Nope.php', 'a', 'b')], fileReader([]));
})->throws(EditRejected::class, 'does not exist');

it('keeps CRLF line endings when the model sends LF', function (): void {
    $windows = str_replace("\n", "\r\n", CALCULATOR);

    $result = EditApplier::apply($windows, new FileEdit('a.php', "    {\n        return \$total / \$quantity;", "    {\n        if (\$quantity === 0) {\n            return 0.0;\n        }\n\n        return \$total / \$quantity;"));

    expect($result)->toContain("if (\$quantity === 0) {\r\n            return 0.0;\r\n        }\r\n")
        ->and(substr_count($result, "\n"))->toBe(substr_count($result, "\r\n"));
});
