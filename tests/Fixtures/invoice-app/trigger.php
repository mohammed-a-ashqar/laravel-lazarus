<?php

declare(strict_types=1);

// Triggers the production bug and prints the exception as Lazarus would capture it.
// Usage: php trigger.php <path/to/lazarus/vendor/autoload.php>

use Alashqar\Lazarus\Capture\ExceptionSnapshot;
use App\InvoiceCalculator;

require $argv[1];
require __DIR__.'/app/InvoiceCalculator.php';

try {
    // A free sample: every line has a zero quantity.
    (new InvoiceCalculator)->averageUnitPrice([['price' => 1200, 'quantity' => 0]]);
} catch (Throwable $exception) {
    echo json_encode(ExceptionSnapshot::fromThrowable($exception)->toArray());
}
