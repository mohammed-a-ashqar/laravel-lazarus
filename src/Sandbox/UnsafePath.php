<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Sandbox;

use RuntimeException;

/**
 * A write outside the allowed paths was attempted. Never retried: the heal is aborted.
 */
final class UnsafePath extends RuntimeException {}
