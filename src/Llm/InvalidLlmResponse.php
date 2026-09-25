<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm;

use RuntimeException;

/**
 * The model answered, but not with what was asked. The message is fed back to it verbatim.
 */
final class InvalidLlmResponse extends RuntimeException {}
