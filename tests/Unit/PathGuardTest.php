<?php

declare(strict_types=1);

use Alashqar\Lazarus\Sandbox\PathGuard;
use Alashqar\Lazarus\Sandbox\UnsafePath;

beforeEach(function (): void {
    $this->guard = new PathGuard(['app/', 'tests/', 'routes/']);
});

it('allows application code, routes and tests', function (string $path): void {
    expect($this->guard->allows($path))->toBeTrue();
})->with([
    'app/Billing/InvoiceCalculator.php',
    'routes/web.php',
    'tests/Feature/Lazarus/InvoiceTest.php',
    './app/Models/User.php',
    'app\\Http\\Controllers\\InvoiceController.php',
]);

it('blocks the hard denylist whatever the allowlist says', function (string $path): void {
    $permissive = new PathGuard(['*']);

    expect($permissive->allows($path))->toBeFalse();
})->with([
    '.env',
    '.env.production',
    'app/.env.backup',
    '.git/config',
    'app/.git/hooks/pre-commit',
    'vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
    'config/services.php',
    'composer.json',
    'composer.lock',
    'database/migrations/2024_01_01_000000_create_users_table.php',
    '.github/workflows/deploy.yml',
    'phpunit.xml',
    'tests/Pest.php',
    'tests/TestCase.php',
    'bootstrap/app.php',
    'public/index.php',
]);

it('blocks anything outside the allowlist', function (): void {
    expect($this->guard->allows('resources/views/welcome.blade.php'))->toBeFalse()
        ->and($this->guard->allows('database/seeders/DatabaseSeeder.php'))->toBeFalse();
});

it('blocks traversal and absolute paths', function (string $path): void {
    expect($this->guard->allows($path))->toBeFalse();
})->with([
    'app/../.env',
    'tests/../../etc/passwd',
    '/etc/passwd',
    'C:\\Windows\\win.ini',
    '',
]);

it('is case-insensitive, as Windows and macOS filesystems are', function (): void {
    expect($this->guard->allows('.ENV'))->toBeFalse()
        ->and($this->guard->allows('Config/app.php'))->toBeFalse()
        ->and($this->guard->allows('APP/Models/User.php'))->toBeTrue();
});

it('explains why a path was refused', function (): void {
    $this->guard->assertWritable('.env');
})->throws(UnsafePath::class, 'Refusing to write ".env"');

it('returns the normalised path when it is allowed', function (): void {
    expect($this->guard->assertWritable('.\\app\\Billing\\Invoice.php'))->toBe('app/Billing/Invoice.php');
});
