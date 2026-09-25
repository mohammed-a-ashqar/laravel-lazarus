<?php

declare(strict_types=1);

use Alashqar\Lazarus\Redaction\Redactor;

function makeRedactor(array $secrets = []): Redactor
{
    return new Redactor(config('lazarus.redaction.keys'), $secrets);
}

it('masks values of sensitive keys in arrays, recursively and case-insensitively', function (): void {
    $redacted = makeRedactor()->redactArray([
        'email_verified' => true,
        'Password' => 'hunter22',
        'password_confirmation' => 'hunter22',
        'profile' => ['name' => 'Ada', 'API-KEY' => 'abc123', 'stripe_secret' => 'sk_live_x'],
        'headers' => ['Authorization' => 'Bearer abc', 'Cookie' => 'laravel_session=xyz'],
        'quantity' => 3,
    ]);

    expect($redacted)->toBe([
        'email_verified' => true,
        'Password' => Redactor::MASK,
        'password_confirmation' => Redactor::MASK,
        'profile' => ['name' => 'Ada', 'API-KEY' => Redactor::MASK, 'stripe_secret' => Redactor::MASK],
        'headers' => ['Authorization' => Redactor::MASK, 'Cookie' => Redactor::MASK],
        'quantity' => 3,
    ]);
});

it('replaces objects in arrays with their type instead of serialising them', function (): void {
    expect(makeRedactor()->redactArray(['user' => new stdClass]))->toBe(['user' => '[stdClass]']);
});

it('removes every literal secret it was given, longest first', function (): void {
    $text = makeRedactor(['s3cr3t-db-pass', 's3cr3t'])->redact('connect with s3cr3t-db-pass, then s3cr3t');

    expect($text)->toBe('connect with [REDACTED], then [REDACTED]');
});

it('learns secrets from a .env file but leaves configuration values alone', function (): void {
    $env = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($env, implode("\n", [
        'APP_NAME=Laravel',
        'APP_ENV=production',
        'APP_KEY=base64:c2VjcmV0LWtleS1tYXRlcmlhbC0xMjM0NTY3ODk=',
        'DB_CONNECTION=mysql',
        'DB_PASSWORD="correct horse battery"',
        "MAIL_PASSWORD='mail-pass-99'",
        'STRIPE_SECRET=sk_live_abcdef123456 # inline comment',
        'SHORT=abc',
        '# COMMENTED=should-not-matter',
    ]));

    $redactor = Redactor::withEnvironmentFile(config('lazarus.redaction.keys'), $env);
    $text = $redactor->redact('Laravel on mysql: pw correct horse battery, mail mail-pass-99, stripe sk_live_abcdef123456, key c2VjcmV0LWtleS1tYXRlcmlhbC0xMjM0NTY3ODk=, abc');

    expect($text)
        ->toContain('Laravel on mysql')
        ->not->toContain('correct horse battery')
        ->not->toContain('mail-pass-99')
        ->not->toContain('sk_live_abcdef123456')
        ->not->toContain('c2VjcmV0LWtleS1tYXRlcmlhbC0xMjM0NTY3ODk=')
        ->toEndWith(', abc');

    unlink($env);
});

it('redacts bearer tokens, JWTs and well-known API key formats', function (string $secret): void {
    expect(makeRedactor()->redact("value: {$secret} end"))->not->toContain($secret);
})->with([
    'bearer' => 'Bearer 9f8e7d6c5b4a3f2e1d0c',
    'jwt' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U',
    'anthropic' => 'sk-ant-api03-AbCdEfGhIjKlMnOpQrStUvWxYz012345',
    'openai' => 'sk-proj-AbCdEfGhIjKlMnOpQrStUvWxYz012345',
    'github' => 'ghp_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789',
    'aws' => 'AKIAIOSFODNN7EXAMPLE',
    'stripe' => 'sk_live_4eC39HqLyjWDarjtT1zdp7dc',
]);

it('redacts quoted values of sensitive keys in code, JSON and logs', function (): void {
    $text = makeRedactor()->redact(<<<'TXT'
    $user->forceFill(['password' => 'hunter22', 'name' => 'Ada']);
    {"api_key": "abc-123-xyz", "count": 3}
    GET /callback?token=tok_998877&page=2
    TXT);

    expect($text)
        ->toContain("'password' => '[REDACTED]'")
        ->toContain("'name' => 'Ada'")
        ->toContain('"api_key": "[REDACTED]"')
        ->toContain('"count": 3')
        ->toContain('?token=[REDACTED]&page=2');
});

it('does not mangle code that merely mentions a sensitive key', function (): void {
    $code = '$password = $request->input(\'password\'); $user->password = Hash::make($password);';

    expect(makeRedactor()->redact($code))->toBe($code);
});

it('redacts authorization and cookie header lines', function (): void {
    $text = makeRedactor()->redact("Host: example.com\nAuthorization: Basic dXNlcjpwYXNz\ncookie: a=b; c=d");

    expect($text)->toBe("Host: example.com\nAuthorization: [REDACTED]\ncookie: [REDACTED]");
});

it('replaces email addresses', function (): void {
    expect(makeRedactor()->redact('Failed to notify ada.lovelace+billing@example.co.uk about invoice 42'))
        ->toBe('Failed to notify [EMAIL] about invoice 42');
});

it('masks card numbers that pass the Luhn check and keeps other long numbers', function (): void {
    $text = makeRedactor()->redact('card 4242 4242 4242 4242, amex 3782-822463-10005, order 1234567890123456');

    expect($text)->toBe('card [CARD], amex [CARD], order 1234567890123456');
});

it('implements the Luhn check', function (string $number, bool $valid): void {
    expect(Redactor::passesLuhn($number))->toBe($valid);
})->with([
    ['4242424242424242', true],
    ['5555 5555 5555 4444', true],
    ['4242424242424241', false],
    ['123', false],
]);
