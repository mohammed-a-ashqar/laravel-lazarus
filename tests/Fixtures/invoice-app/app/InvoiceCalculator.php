<?php

declare(strict_types=1);

namespace App;

final class InvoiceCalculator
{
    /**
     * @param  list<array{price: int, quantity: int}>  $lines  Prices in cents.
     */
    public function total(array $lines): int
    {
        return array_sum(array_map(static fn (array $line): int => $line['price'] * $line['quantity'], $lines));
    }

    /**
     * The average price paid per unit, in cents.
     *
     * @param  list<array{price: int, quantity: int}>  $lines
     */
    public function averageUnitPrice(array $lines): float
    {
        $quantity = array_sum(array_column($lines, 'quantity'));

        return $this->total($lines) / $quantity;
    }
}
