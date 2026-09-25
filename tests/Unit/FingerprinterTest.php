<?php

declare(strict_types=1);

use Alashqar\Lazarus\Capture\ExceptionSnapshot;
use Alashqar\Lazarus\Capture\Fingerprinter;
use Alashqar\Lazarus\Support\Project;

function incidentSnapshot(string $message, int $line = 18, string $class = DivisionByZeroError::class, string $file = '/srv/app/app/Billing/InvoiceCalculator.php'): ExceptionSnapshot
{
    return new ExceptionSnapshot($class, $message, $file, $line, [
        ['file' => '/srv/app/app/Http/Controllers/InvoiceController.php', 'line' => 31, 'class' => 'App\\Billing\\InvoiceCalculator', 'function' => 'averageUnitPrice'],
        ['file' => '/srv/app/vendor/laravel/framework/src/Illuminate/Routing/Controller.php', 'line' => 54, 'class' => null, 'function' => 'call_user_func_array'],
    ]);
}

beforeEach(function (): void {
    $this->fingerprinter = new Fingerprinter(new Project('/srv/app'));
});

it('is stable for the same bug', function (): void {
    expect($this->fingerprinter->fingerprint(incidentSnapshot('Division by zero')))
        ->toBe($this->fingerprinter->fingerprint(incidentSnapshot('Division by zero')))
        ->toHaveLength(64);
});

it('ignores ids, numbers, uuids and hashes in the message', function (): void {
    $a = incidentSnapshot('Order 1001 for user 9f1c2e4a-3b5d-4c6e-8f7a-1b2c3d4e5f60 failed (hash 5d41402abc4b2a76b9719d911017c592)');
    $b = incidentSnapshot('Order 77 for user 00000000-1111-2222-3333-444444444444 failed (hash e99a18c428cb38d5f260853678922e03)');

    expect($this->fingerprinter->fingerprint($a))->toBe($this->fingerprinter->fingerprint($b));
});

it('separates different lines, classes and messages', function (): void {
    $base = $this->fingerprinter->fingerprint(incidentSnapshot('Division by zero'));

    expect($this->fingerprinter->fingerprint(incidentSnapshot('Division by zero', line: 19)))->not->toBe($base)
        ->and($this->fingerprinter->fingerprint(incidentSnapshot('Division by zero', class: ArithmeticError::class)))->not->toBe($base)
        ->and($this->fingerprinter->fingerprint(incidentSnapshot('Modulo by zero')))->not->toBe($base);
});

it('uses the innermost application frame and skips vendor code', function (): void {
    $vendorThrow = incidentSnapshot('Undefined array key "total"', 120, ErrorException::class, '/srv/app/vendor/laravel/framework/src/Illuminate/Support/Arr.php');

    expect($this->fingerprinter->origin($vendorThrow))->toBe(['app/Http/Controllers/InvoiceController.php', 31])
        ->and($this->fingerprinter->origin(incidentSnapshot('x')))->toBe(['app/Billing/InvoiceCalculator.php', 18]);
});

it('is independent of where the application is deployed', function (): void {
    $elsewhere = new Fingerprinter(new Project('C:\\sites\\app'));
    $windows = new ExceptionSnapshot(DivisionByZeroError::class, 'Division by zero', 'C:\\sites\\app\\app\\Billing\\InvoiceCalculator.php', 18, []);

    expect($elsewhere->fingerprint($windows))->toBe($this->fingerprinter->fingerprint(new ExceptionSnapshot(DivisionByZeroError::class, 'Division by zero', '/srv/app/app/Billing/InvoiceCalculator.php', 18, [])));
});

it('normalizes messages', function (): void {
    expect(Fingerprinter::normalizeMessage("Row  42 of\ttable 3.5 missing"))->toBe('Row {n} of table {n} missing');
});
