<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Notifications;

use Alashqar\Lazarus\Events\FixPublished;
use Alashqar\Lazarus\Events\HealingFailed;
use Alashqar\Lazarus\Events\IncidentCaptured;
use Alashqar\Lazarus\Models\Incident;
use Alashqar\Lazarus\Support\Settings;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\Message;
use Throwable;

/**
 * Sends a short message by email, Telegram and/or a Slack or Discord webhook. All free; each
 * channel is used only when configured, and a failing channel never breaks the app or a heal.
 */
final class Notifier
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Factory $http,
        private readonly Mailer $mailer,
        private readonly string $appName,
        private readonly string $environment,
    ) {}

    public function captured(IncidentCaptured $event): void
    {
        if (! $event->isNew || $event->fromLog || ! $this->wants('captured')) {
            return;
        }

        $this->send('New error', $this->lines($event->incident, [
            'Lazarus will heal it when you run: php artisan lazarus:heal '.$event->incident->id,
        ]));
    }

    public function fixed(FixPublished $event): void
    {
        if (! $this->wants('fixed')) {
            return;
        }

        $this->send('Fix ready for review', $this->lines($event->incident, [
            'Fix: '.$event->report->patch->summary,
            sprintf('Confidence: %d%%, test red then green, full suite passes', (int) round($event->report->diagnosis->confidence * 100)),
            'Review: '.($event->result->url ?? $event->result->path ?? $event->result->description),
        ]));
    }

    public function failed(HealingFailed $event): void
    {
        if (! $this->wants('failed')) {
            return;
        }

        $this->send('Could not fix automatically', $this->lines($event->incident, ['Reason: '.$event->reason]));
    }

    /**
     * @return list<string> The channels that are configured.
     */
    public function channels(): array
    {
        return array_values(array_filter([
            $this->mailRecipients() !== [] ? 'mail' : null,
            $this->telegram() !== null ? 'telegram' : null,
            $this->settings->nullableString('notifications.webhook') !== null ? 'webhook' : null,
        ]));
    }

    /**
     * Send to every configured channel.
     *
     * @param  list<string>  $lines
     * @return array<string, string|null> Channel => error message, or null when it was sent.
     */
    public function send(string $title, array $lines): array
    {
        $subject = "[{$this->appName}] {$title}";
        $text = $subject."\n\n".implode("\n", $lines);
        $results = [];

        if (($recipients = $this->mailRecipients()) !== []) {
            $results['mail'] = $this->attempt(fn () => $this->mailer->raw($text, static function (Message $message) use ($recipients, $subject): void {
                $message->to($recipients)->subject($subject);
            }));
        }

        if (($telegram = $this->telegram()) !== null) {
            $results['telegram'] = $this->attempt(fn () => $this->http->timeout(5)
                ->post("https://api.telegram.org/bot{$telegram['token']}/sendMessage", [
                    'chat_id' => $telegram['chat_id'],
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ])->throw());
        }

        if (($webhook = $this->settings->nullableString('notifications.webhook')) !== null) {
            // Slack reads "text", Discord reads "content" (limited to 2000 characters).
            $results['webhook'] = $this->attempt(fn () => $this->http->timeout(5)
                ->post($webhook, ['text' => $text, 'content' => mb_substr($text, 0, 1900)])
                ->throw());
        }

        // A connection error quotes the URL, and the Telegram URL carries the bot token.
        if ($telegram !== null) {
            $results = array_map(static fn (?string $error): ?string => $error === null ? null : str_replace($telegram['token'], '***', $error), $results);
        }

        return $results;
    }

    /**
     * @param  list<string>  $extra
     * @return list<string>
     */
    private function lines(Incident $incident, array $extra): array
    {
        return [
            "{$incident->shortClass()}: ".mb_substr($incident->message, 0, 300),
            'Where: '.$incident->location(),
            "Environment: {$this->environment} · incident #{$incident->id} · seen {$incident->occurrences} time(s)",
            ...$extra,
        ];
    }

    private function wants(string $event): bool
    {
        return in_array($event, $this->settings->strings('notifications.events', ['captured', 'fixed', 'failed']), true)
            && $this->channels() !== [];
    }

    /**
     * @return list<string>
     */
    private function mailRecipients(): array
    {
        $raw = $this->settings->nullableString('notifications.mail') ?? '';

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false));
    }

    /**
     * @return array{token: string, chat_id: string}|null
     */
    private function telegram(): ?array
    {
        $token = $this->settings->nullableString('notifications.telegram.token');
        $chat = $this->settings->nullableString('notifications.telegram.chat_id');

        return $token !== null && $chat !== null ? ['token' => $token, 'chat_id' => $chat] : null;
    }

    private function attempt(callable $send): ?string
    {
        try {
            $send();

            return null;
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
    }
}
