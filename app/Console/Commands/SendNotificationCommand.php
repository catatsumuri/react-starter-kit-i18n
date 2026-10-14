<?php

namespace App\Console\Commands;

use App\Actions\Notifications\SendGeneralNotification;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SendNotificationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:send
        {user? : User ID or email address}
        {--alert : Send to the shared alert mailbox and Slack channel instead of a user}
        {--channel=* : Alert channels to use (mail, slack); defaults to all}
        {--title= : Notification title}
        {--body= : Notification body}
        {--url= : Optional relative or HTTPS action URL}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a notification to a registered user or the shared alert recipients';

    public function handle(SendGeneralNotification $sendGeneralNotification): int
    {
        $user = null;

        if (! $this->option('alert')) {
            $user = $this->findUser((string) $this->argument('user'));

            if ($user === null) {
                $this->error('The specified user was not found.');

                return self::FAILURE;
            }
        }

        $title = $this->requiredValue('title', 'Notification title');
        $body = $this->requiredValue('body', 'Notification body');

        if ($title === null || $body === null) {
            return self::FAILURE;
        }

        $actionUrl = $this->optionalUrl();

        try {
            if ($user === null) {
                return $this->sendAlert($sendGeneralNotification, $title, $body, $actionUrl);
            }

            $sendGeneralNotification->handle($user, $title, $body, $actionUrl);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Notification sent to {$user->name} ({$user->email}).");

        return self::SUCCESS;
    }

    protected function sendAlert(
        SendGeneralNotification $sendGeneralNotification,
        string $title,
        string $body,
        ?string $actionUrl,
    ): int {
        /** @var array<int, string> $requested */
        $requested = $this->option('channel') ?: ['mail', 'slack'];

        if (array_diff($requested, ['mail', 'slack']) !== []) {
            $this->error('The --channel option accepts only mail and slack.');

            return self::FAILURE;
        }

        $sent = $sendGeneralNotification->toAlertRecipients($title, $body, $actionUrl, $requested);

        if ($sent === []) {
            $this->error('No alert recipient is configured for the requested channels.');

            return self::FAILURE;
        }

        $this->info('Alert sent via: '.implode(', ', $sent).'.');

        return self::SUCCESS;
    }

    protected function findUser(string $identifier): ?User
    {
        if (ctype_digit($identifier)) {
            return User::query()->find((int) $identifier);
        }

        return User::query()->where('email', $identifier)->first();
    }

    protected function requiredValue(string $option, string $question): ?string
    {
        $value = $this->option($option);

        if ($value === null && $this->input->isInteractive()) {
            $value = $this->ask($question);
        }

        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            $this->error("The --{$option} option is required.");

            return null;
        }

        return $value;
    }

    protected function optionalUrl(): ?string
    {
        $url = $this->option('url');

        if ($url === null && $this->input->isInteractive()) {
            $url = $this->ask('Action URL (optional)');
        }

        $url = is_string($url) ? trim($url) : '';

        if ($url === '') {
            return null;
        }

        return $url;
    }
}
