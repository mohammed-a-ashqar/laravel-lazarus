<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Commands;

use Alashqar\Lazarus\Notifications\Notifier;
use Illuminate\Console\Command;

final class NotifyTestCommand extends Command
{
    protected $signature = 'lazarus:notify-test';

    protected $description = 'Send a test message to every configured notification channel';

    public function handle(Notifier $notifier): int
    {
        if ($notifier->channels() === []) {
            $this->components->warn('No notification channel is configured. Set LAZARUS_NOTIFY_MAIL, LAZARUS_NOTIFY_TELEGRAM_TOKEN and LAZARUS_NOTIFY_TELEGRAM_CHAT, or LAZARUS_NOTIFY_WEBHOOK.');

            return self::FAILURE;
        }

        $results = $notifier->send('Test notification', ['Lazarus notifications work. You will hear about new errors and fixes here.']);
        $failed = false;

        foreach ($results as $channel => $error) {
            $this->components->twoColumnDetail(ucfirst($channel), $error === null ? '<fg=green;options=bold>SENT</>' : '<fg=red;options=bold>FAILED</> '.$error);
            $failed = $failed || $error !== null;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
