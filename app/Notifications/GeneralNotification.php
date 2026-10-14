<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

class GeneralNotification extends Notification
{
    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
        public array $channels = ['database'],
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
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

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title)
            ->line($this->body);

        if ($this->actionUrl !== null) {
            $message->action(__('View details'), url($this->actionUrl));
        }

        return $message;
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->text($this->title)
            ->headerBlock($this->title)
            ->sectionBlock(function ($block): void {
                $block->text($this->body);
            });
    }
}
