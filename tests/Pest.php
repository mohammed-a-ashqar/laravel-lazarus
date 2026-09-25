<?php

declare(strict_types=1);

use Alashqar\Lazarus\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'EndToEnd');
