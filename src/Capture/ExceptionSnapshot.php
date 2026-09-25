<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Capture;

use Throwable;

/**
 * A serialisable copy of a throwable: its class, message, origin and call stack.
 */
final readonly class ExceptionSnapshot
{
    /**
     * @param  list<array{file: string|null, line: int|null, class: string|null, function: string|null}>  $frames
     */
    public function __construct(
        public string $class,
        public string $message,
        public string $file,
        public int $line,
        public array $frames,
    ) {}

    public static function fromThrowable(Throwable $exception): self
    {
        $frames = [];

        foreach ($exception->getTrace() as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'],
            ];
        }

        return new self($exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), $frames);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $frames = [];

        foreach (is_array($data['frames'] ?? null) ? $data['frames'] : [] as $frame) {
            if (! is_array($frame)) {
                continue;
            }

            $frames[] = [
                'file' => is_string($frame['file'] ?? null) ? $frame['file'] : null,
                'line' => is_int($frame['line'] ?? null) ? $frame['line'] : null,
                'class' => is_string($frame['class'] ?? null) ? $frame['class'] : null,
                'function' => is_string($frame['function'] ?? null) ? $frame['function'] : null,
            ];
        }

        return new self(
            is_string($data['class'] ?? null) ? $data['class'] : 'Exception',
            is_string($data['message'] ?? null) ? $data['message'] : '',
            is_string($data['file'] ?? null) ? $data['file'] : '',
            is_int($data['line'] ?? null) ? $data['line'] : 0,
            $frames,
        );
    }

    /**
     * The throw site followed by every caller, innermost first.
     *
     * @return list<array{file: string|null, line: int|null, class: string|null, function: string|null}>
     */
    public function callStack(): array
    {
        return [
            ['file' => $this->file, 'line' => $this->line, 'class' => null, 'function' => null],
            ...$this->frames,
        ];
    }

    /**
     * @return array{class: string, message: string, file: string, line: int, frames: list<array{file: string|null, line: int|null, class: string|null, function: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'frames' => $this->frames,
        ];
    }
}
