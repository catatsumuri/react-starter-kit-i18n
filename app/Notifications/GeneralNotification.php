<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class GeneralNotification extends Notification
{
    public function __construct(
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification for the database.
     *
     * @return array{title: string, body: string, action_url: string|null}
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
        ];
    }
}
