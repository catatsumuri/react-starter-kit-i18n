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
        {user : User ID or email address}
        {--title= : Notification title}
        {--body= : Notification body}
        {--url= : Optional relative or HTTPS action URL}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a database notification to a registered user';

    public function handle(SendGeneralNotification $sendGeneralNotification): int
    {
        $user = $this->findUser((string) $this->argument('user'));

        if ($user === null) {
            $this->error('The specified user was not found.');

            return self::FAILURE;
        }

        $title = $this->requiredValue('title', 'Notification title');
        $body = $this->requiredValue('body', 'Notification body');

        if ($title === null || $body === null) {
            return self::FAILURE;
        }

        $actionUrl = $this->optionalUrl();

        try {
            $sendGeneralNotification->handle($user, $title, $body, $actionUrl);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Notification sent to {$user->name} ({$user->email}).");

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
