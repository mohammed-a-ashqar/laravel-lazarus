<?php

declare(strict_types=1);

use App\InvoiceCalculator;

it('totals the lines of an invoice', function (): void {
    expect((new InvoiceCalculator)->total([
        ['price' => 1200, 'quantity' => 2],
        ['price' => 500, 'quantity' => 1],
    ]))->toBe(2900);
});

it('averages the unit price across lines', function (): void {
    expect((new InvoiceCalculator)->averageUnitPrice([
        ['price' => 1000, 'quantity' => 1],
        ['price' => 2000, 'quantity' => 3],
    ]))->toBe(1750.0);
});
