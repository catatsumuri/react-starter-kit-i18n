<?php

namespace App\Notifications;

use App\Support\SecurityEvent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityNotification extends Notification
{
    public function __construct(public SecurityEvent $event) {}

    /**
     * Get the notification's delivery channels.
     *
     * Mail only: an in-app notification can be read and dismissed by whoever holds the session.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->event->subject())
            ->line($this->event->message())
            ->line(__('If you did not make this change, reset your password and review your account security settings immediately.'));
    }
}
