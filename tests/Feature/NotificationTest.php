<?php

namespace Tests\Feature;

use App\Actions\Notifications\SendGeneralNotification;
use App\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_notification_can_be_stored_for_a_user(): void
    {
        $user = User::factory()->create();

        (new SendGeneralNotification)->handle(
            $user,
            'Maintenance scheduled',
            'The service will be unavailable tonight.',
            '/dashboard',
        );

        $notification = $user->notifications()->sole();

        $this->assertSame(GeneralNotification::class, $notification->type);
        $this->assertSame([
            'title' => 'Maintenance scheduled',
            'body' => 'The service will be unavailable tonight.',
            'action_url' => '/dashboard',
        ], $notification->data);
        $this->assertNull($notification->read_at);
    }

    public function test_an_insecure_action_url_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        (new SendGeneralNotification)->handle(
            $user,
            'Important update',
            'Review this update.',
            'http://example.com/update',
        );
    }

    public function test_a_user_can_mark_their_notification_as_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new GeneralNotification('Welcome', 'Thanks for joining.', '/dashboard'));
        $notification = $user->notifications()->sole();

        $this->actingAs($user)
            ->patch(route('notifications.read', $notification))
            ->assertRedirect('/dashboard');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_user_cannot_read_another_users_notification(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUser->notify(new GeneralNotification('Private', 'For the recipient only.'));
        $notification = $otherUser->notifications()->sole();

        $this->actingAs($user)
            ->patch(route('notifications.read', $notification))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_an_alert_is_sent_to_the_configured_mail_and_slack_routes(): void
    {
        Notification::fake();
        config([
            'services.alerts.mail_to' => 'ops@example.com',
            'services.alerts.slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        ]);

        $sent = (new SendGeneralNotification)->toAlertRecipients('Down', 'Queue is failing.');

        $this->assertSame(['mail', 'slack'], $sent);
        Notification::assertSentOnDemand(
            GeneralNotification::class,
            function (GeneralNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
                return $channels === ['mail', 'slack']
                    && $notifiable->routes['mail'] === 'ops@example.com'
                    && $notifiable->routes['slack'] === 'https://hooks.slack.com/services/T/B/X';
            },
        );
    }

    public function test_an_alert_skips_unconfigured_channels(): void
    {
        Notification::fake();
        config([
            'services.alerts.mail_to' => 'ops@example.com',
            'services.alerts.slack_webhook_url' => null,
        ]);

        $sent = (new SendGeneralNotification)->toAlertRecipients('Down', 'Queue is failing.');

        $this->assertSame(['mail'], $sent);
    }

    public function test_an_alert_rejects_an_insecure_action_url(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SendGeneralNotification)->toAlertRecipients('Down', 'Body', 'http://example.com');
    }

    public function test_the_alert_command_sends_to_requested_channels(): void
    {
        Notification::fake();
        config([
            'services.alerts.mail_to' => 'ops@example.com',
            'services.alerts.slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        ]);

        $this->artisan('notifications:send', [
            '--alert' => true,
            '--channel' => ['mail'],
            '--title' => 'Test',
            '--body' => 'Test alert',
            '--url' => '',
        ])->assertSuccessful();

        Notification::assertSentOnDemand(
            GeneralNotification::class,
            fn (GeneralNotification $notification, array $channels): bool => $channels === ['mail'],
        );
    }

    public function test_the_alert_command_fails_without_recipients(): void
    {
        config([
            'services.alerts.mail_to' => null,
            'services.alerts.slack_webhook_url' => null,
        ]);

        $this->artisan('notifications:send', [
            '--alert' => true,
            '--title' => 'Test',
            '--body' => 'Test alert',
            '--url' => '',
        ])->assertFailed();
    }

    public function test_reported_exceptions_trigger_a_throttled_alert(): void
    {
        Notification::fake();
        Cache::flush();
        config(['services.alerts.mail_to' => 'ops@example.com']);

        $exception = new RuntimeException('Boom');

        report($exception);
        report($exception);

        Notification::assertSentOnDemandTimes(GeneralNotification::class, 1);
    }

    public function test_an_alert_is_still_sent_when_the_cache_is_unavailable(): void
    {
        Notification::fake();
        config(['services.alerts.mail_to' => 'ops@example.com']);
        Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('cache down'));

        report(new RuntimeException('Boom'));

        Notification::assertSentOnDemandTimes(GeneralNotification::class, 1);
    }

    public function test_the_exception_command_sends_through_the_throwable_alert_path(): void
    {
        Notification::fake();
        Cache::flush();
        config(['services.alerts.mail_to' => 'ops@example.com']);

        $this->artisan('notifications:send', ['--exception' => true, '--body' => 'Probe'])
            ->assertSuccessful();

        $this->artisan('notifications:send', ['--exception' => true, '--body' => 'Probe'])
            ->assertFailed();

        $this->artisan('notifications:send', [
            '--exception' => true,
            '--no-throttle' => true,
            '--body' => 'Probe',
        ])->assertSuccessful();

        Notification::assertSentOnDemandTimes(GeneralNotification::class, 2);
    }
}
