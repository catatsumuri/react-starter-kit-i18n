<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GeneralNotification extends Notification
{
    public function __construct(
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
        public bool $sendMail = false,
        /** @var array<string, bool|int|string|null> */
        public array $metadata = [],
        public bool $storeInDatabase = true,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return array_keys(array_filter([
            'database' => $this->storeInDatabase,
            'mail' => $this->sendMail,
        ]));
    }

    /**
     * Get the array representation of the notification for the database.
     *
     * @return array<string, bool|int|string|null>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            ...$this->metadata,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title)
            ->line($this->body);

        if ($this->actionUrl !== null) {
            $url = str_starts_with($this->actionUrl, '/')
                ? url($this->actionUrl)
                : $this->actionUrl;

            $message->action(__('Review security activity'), $url);
        }

        return $message;
    }
}
