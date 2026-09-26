<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm\Drivers;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Waits out rate limits and overloads instead of failing a heal: free tiers such as Groq's allow
 * only a few thousand tokens a minute, which one heal can exceed.
 */
final class RateLimitRetry
{
    public const RETRYABLE = [429, 503, 529];

    private const ATTEMPTS = 4;

    private const MAX_WAIT_SECONDS = 60;

    public static function apply(Factory $http): PendingRequest
    {
        return $http->retry(
            self::ATTEMPTS,
            static fn (int $attempt, mixed $exception): int => self::delayMilliseconds($attempt, $exception instanceof Throwable ? $exception : null),
            static fn (Throwable $exception): bool => $exception instanceof RequestException
                && in_array($exception->response->status(), self::RETRYABLE, true),
            throw: false,
        );
    }

    /**
     * The server's Retry-After (or Groq's "try again in 14.43s") when given, exponential otherwise.
     */
    public static function delayMilliseconds(int $attempt, ?Throwable $exception): int
    {
        $seconds = null;

        if ($exception instanceof RequestException) {
            $header = $exception->response->header('Retry-After');

            if (is_numeric($header)) {
                $seconds = (float) $header;
            } elseif (preg_match('/try again in ([\d.]+)s/i', $exception->response->body(), $match) === 1) {
                $seconds = (float) $match[1];
            }
        }

        $seconds ??= 2 ** $attempt;

        return (int) ceil(min(max($seconds, 1), self::MAX_WAIT_SECONDS) * 1000);
    }
}
