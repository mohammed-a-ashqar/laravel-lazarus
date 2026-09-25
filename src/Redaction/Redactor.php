<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Redaction;

/**
 * Removes secrets and personal data from anything Lazarus stores or sends to a model.
 *
 * It is deliberately greedy: a false positive costs a slightly less useful prompt, a false
 * negative leaks a credential. It never touches code structure, only literal values.
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    /**
     * Environment keys whose values are configuration, not secrets. Redacting them would
     * only mangle code (APP_NAME=Laravel would erase every "Laravel" in a stack trace).
     */
    private const PUBLIC_ENV_KEYS = '/^(APP_(NAME|ENV|DEBUG|URL|LOCALE|FALLBACK_LOCALE|FAKER_LOCALE|TIMEZONE|MAINTENANCE_\w+)|LOG_\w+|VITE_\w+|DB_(HOST|DATABASE|PORT)|\w+_(DRIVER|CONNECTION|STORE|MAILER|CHANNEL|STACK|LEVEL|PORT|LIFETIME|SCHEME|ENCRYPT|PATH|DOMAIN|PREFIX|REGION|MODEL|QUEUE|DISK|HOST))$/';

    private const TRIVIAL_VALUES = ['true', 'false', 'null', 'empty', 'local', 'production', 'staging', 'testing', 'localhost', '127.0.0.1'];

    /** @var list<string> */
    private readonly array $keys;

    /** @var list<string> */
    private readonly array $secrets;

    /**
     * @param  list<string>  $keys  Sensitive key names, matched case-insensitively as substrings.
     * @param  list<string>  $secrets  Literal values that must never appear in output.
     */
    public function __construct(array $keys, array $secrets = [])
    {
        $this->keys = array_values(array_unique(array_filter(array_map(self::normalizeKey(...), $keys))));

        $secrets = array_values(array_unique(array_filter($secrets, static fn (string $secret): bool => $secret !== '')));
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->secrets = $secrets;
    }

    /**
     * Build a redactor that also knows every secret value defined in a .env file.
     *
     * @param  list<string>  $keys
     */
    public static function withEnvironmentFile(array $keys, ?string $envFile, int $minimumLength = 6): self
    {
        return new self($keys, $envFile === null ? [] : self::secretsFromEnvFile($envFile, $minimumLength));
    }

    /**
     * @return list<string>
     */
    public static function secretsFromEnvFile(string $path, int $minimumLength = 6): array
    {
        if (! is_file($path) || ($lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) === false) {
            return [];
        }

        $secrets = [];

        foreach ($lines as $line) {
            if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match)) {
                continue;
            }

            $value = trim($match[2]);

            if (preg_match('/^(["\'])(.*)\1$/', $value, $quoted)) {
                $value = $quoted[2];
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }

            if (preg_match(self::PUBLIC_ENV_KEYS, $match[1]) || strlen($value) < $minimumLength) {
                continue;
            }

            if (in_array(strtolower($value), self::TRIVIAL_VALUES, true) || str_starts_with($value, '${')) {
                continue;
            }

            $secrets[] = $value;

            // Laravel keys are stored as "base64:...". The decoded form is never printed, but
            // the encoded part without its prefix sometimes is.
            if (str_starts_with($value, 'base64:')) {
                $secrets[] = substr($value, 7);
            }
        }

        return $secrets;
    }

    public function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        if ($this->secrets !== []) {
            $text = str_replace($this->secrets, self::MASK, $text);
        }

        $keys = $this->keyPattern();

        $replacements = [
            // "Authorization: Basic ...", "Cookie: a=b" header lines.
            '/^(\s*(?:authorization|proxy-authorization|cookie|set-cookie|x-api-key)\s*:\s*).+$/im' => '$1'.self::MASK,
            // Bearer tokens anywhere.
            '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/' => 'Bearer '.self::MASK,
            // JSON Web Tokens.
            '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]+/' => self::MASK,
            // Well-known credential formats.
            '/\b(?:sk-(?:ant-|proj-)?[A-Za-z0-9_-]{20,}|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|AKIA[0-9A-Z]{16}|xox[baprs]-[A-Za-z0-9-]{10,}|(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{16,})\b/' => self::MASK,
            // Quoted values of sensitive keys: 'password' => 'hunter22', "api_key": "abc".
            '/((["\'])[\w.-]*(?:'.$keys.')[\w.-]*\2\s*(?:=>|:)\s*)(["\'])(?:\\\\.|(?!\3).)*\3/i' => '$1$3'.self::MASK.'$3',
            // key=value pairs in URLs, logs and env-style text: ?token=abc&password=secret.
            '/\b([\w.-]*(?:'.$keys.')[\w.-]*=)(?![\s"\'$(])[^\s&"\'<>]+/i' => '$1'.self::MASK,
            // Email addresses.
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}\b/' => '[EMAIL]',
        ];

        $text = (string) preg_replace(array_keys($replacements), array_values($replacements), $text);

        return (string) preg_replace_callback(
            '/(?<![\w.-])\d(?:[ -]?\d){12,18}(?![\w-])/',
            static fn (array $match): string => self::passesLuhn($match[0]) ? '[CARD]' : $match[0],
            $text,
        );
    }

    /**
     * Redact an array recursively: sensitive keys lose their value, every string is scanned.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redactArray(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $redacted[$key] = self::MASK;

                continue;
            }

            $redacted[$key] = match (true) {
                is_array($value) => $this->redactArray($value),
                is_string($value) => $this->redact($value),
                is_scalar($value), $value === null => $value,
                default => '['.get_debug_type($value).']',
            };
        }

        return $redacted;
    }

    public function isSensitiveKey(string $key): bool
    {
        $key = self::normalizeKey($key);

        foreach ($this->keys as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    public static function passesLuhn(string $number): bool
    {
        $digits = (string) preg_replace('/\D/', '', $number);
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    private function keyPattern(): string
    {
        if ($this->keys === []) {
            return '(?!)';
        }

        return implode('|', array_map(
            static fn (string $key): string => implode('[_-]?', array_map(static fn (string $char): string => preg_quote($char, '/'), str_split($key))),
            $this->keys,
        ));
    }

    private static function normalizeKey(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));
    }
}
