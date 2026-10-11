<?php

namespace Tests\Feature;

use App\Actions\Notifications\SendGeneralNotification;
use App\Events\SecurityActivityObserved;
use App\Events\UserEmailChanged;
use App\Jobs\SendSecurityAlert;
use App\Jobs\SendSecurityNotification;
use App\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.detection.enabled', true);
        config()->set('security.detection.cache_store', 'array');
        Cache::store('array')->clear();
    }

    public function test_network_failure_threshold_dispatches_one_deduplicated_alert(): void
    {
        Queue::fake();
        config()->set('security.detection.thresholds.network_failures.count', 2);

        event($this->observed(result: 'failure', requestId: 'request-1'));
        Queue::assertNotPushed(SendSecurityAlert::class);

        event($this->observed(result: 'failure', requestId: 'request-2'));
        event($this->observed(result: 'failure', requestId: 'request-3'));

        Queue::assertPushed(
            SendSecurityAlert::class,
            fn (SendSecurityAlert $job): bool => $job->alert === 'authentication.network_failures'
                && $job->context['ip_address'] === '203.0.113.x',
        );
        Queue::assertPushed(SendSecurityAlert::class, 1);
    }

    public function test_security_alert_title_is_translated_for_the_configured_locale(): void
    {
        app()->setLocale('ja');
        config()->set('security.detection.alert_channel', 'security');
        Log::shouldReceive('channel')->once()->with('security')->andReturnSelf();
        Log::shouldReceive('critical')->once()->with(
            '同一ネットワークからの認証失敗が急増しました',
            ['count' => 25],
        );

        $job = new SendSecurityAlert(
            alert: 'authentication.network_failures',
            context: ['count' => 25],
        );

        $job->handle();
    }

    public function test_known_account_failures_are_aggregated_across_networks(): void
    {
        Queue::fake();
        config()->set('security.detection.thresholds.account_networks.count', 2);

        event($this->observed(result: 'failure', networkKey: 'network-a', requestId: 'request-1'));
        event($this->observed(result: 'failure', networkKey: 'network-b', requestId: 'request-2'));

        Queue::assertPushed(
            SendSecurityAlert::class,
            fn (SendSecurityAlert $job): bool => $job->alert === 'authentication.account_networks'
                && $job->context['target_user_id'] === 42,
        );
    }

    public function test_new_client_is_notified_once_even_when_the_ip_changes(): void
    {
        Queue::fake();

        event($this->observed(result: 'success', requestId: 'request-1'));
        event($this->observed(
            result: 'success',
            networkKey: 'another-network',
            maskedIpAddress: '198.51.100.x',
            requestId: 'request-2',
        ));

        Queue::assertPushed(
            SendSecurityNotification::class,
            fn (SendSecurityNotification $job): bool => $job->event === 'authentication.new_client',
        );
        Queue::assertPushed(SendSecurityNotification::class, 1);
    }

    public function test_detection_store_failure_does_not_escape_the_event_listener(): void
    {
        Queue::fake();
        config()->set('security.detection.cache_store', 'missing-store');

        event($this->observed(result: 'failure'));

        Queue::assertNothingPushed();
    }

    public function test_important_activity_dispatches_an_owner_notification(): void
    {
        Queue::fake();
        $event = $this->observed(result: 'success', event: 'account.password_changed');

        event($event);

        Queue::assertPushed(
            SendSecurityNotification::class,
            fn (SendSecurityNotification $job): bool => $job->event === 'account.password_changed'
                && $job->userId === $event->targetUserId,
        );
    }

    public function test_email_change_notification_preserves_the_previous_delivery_address(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email' => 'new@example.com']);

        event(new UserEmailChanged($user, 'old@example.com'));

        Queue::assertPushed(
            SendSecurityNotification::class,
            fn (SendSecurityNotification $job): bool => $job->event === 'account.email_changed'
                && $job->additionalMailAddress === 'old@example.com',
        );
    }

    public function test_security_notification_is_stored_once_for_the_same_request(): void
    {
        config()->set('security.notifications.mail', false);
        $user = User::factory()->create();
        $job = new SendSecurityNotification(
            userId: $user->getKey(),
            event: 'account.password_changed',
            title: 'Password changed',
            body: 'The password changed.',
            requestId: 'same-request',
        );

        $job->handle(app(SendGeneralNotification::class));
        $job->handle(app(SendGeneralNotification::class));

        $this->assertCount(1, $user->notifications()->get());
        $this->assertSame('account.password_changed', $user->notifications()->sole()->data['security_event']);
    }

    public function test_general_notification_only_adds_mail_when_requested(): void
    {
        $databaseOnly = new GeneralNotification('Title', 'Body');
        $withMail = new GeneralNotification('Title', 'Body', sendMail: true);
        $mailOnly = new GeneralNotification('Title', 'Body', sendMail: true, storeInDatabase: false);

        $this->assertSame(['database'], $databaseOnly->via(new \stdClass));
        $this->assertSame(['database', 'mail'], $withMail->via(new \stdClass));
        $this->assertSame(['mail'], $mailOnly->via(new \stdClass));
    }

    public function test_email_change_notification_is_sent_to_the_current_and_previous_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'new@example.com']);
        $job = new SendSecurityNotification(
            userId: $user->getKey(),
            event: 'account.email_changed',
            title: 'Email address changed',
            body: 'The email address changed.',
            requestId: 'email-change-request',
            additionalMailAddress: 'old@example.com',
        );

        $job->handle(app(SendGeneralNotification::class));

        Notification::assertSentTo(
            $user,
            GeneralNotification::class,
            fn (GeneralNotification $notification, array $channels): bool => $notification->sendMail
                && $channels === ['database', 'mail'],
        );
        Notification::assertSentOnDemand(
            GeneralNotification::class,
            fn (GeneralNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $channels === ['mail']
                && $notifiable->routeNotificationFor('mail') === 'old@example.com',
        );
    }

    public function test_disabled_event_does_not_dispatch_an_owner_notification(): void
    {
        Queue::fake();
        $this->configureNotificationEvent('account.password_changed', [
            'database' => false,
            'mail' => false,
        ]);

        event($this->observed(result: 'success', event: 'account.password_changed'));

        Queue::assertNotPushed(SendSecurityNotification::class);
    }

    public function test_event_can_be_configured_for_database_without_mail(): void
    {
        Notification::fake();
        $this->configureNotificationEvent('account.password_changed', [
            'database' => true,
            'mail' => false,
        ]);
        $user = User::factory()->create();
        $job = new SendSecurityNotification(
            userId: $user->getKey(),
            event: 'account.password_changed',
            title: 'Password changed',
            body: 'The password changed.',
            requestId: 'database-only-request',
        );

        $job->handle(app(SendGeneralNotification::class));

        Notification::assertSentTo(
            $user,
            GeneralNotification::class,
            fn (GeneralNotification $notification, array $channels): bool => ! $notification->sendMail
                && $channels === ['database'],
        );
    }

    private function observed(
        string $result,
        string $event = 'authentication.login_succeeded',
        string $networkKey = 'network-key',
        string $maskedIpAddress = '203.0.113.x',
        string $requestId = 'request-id',
    ): SecurityActivityObserved {
        return new SecurityActivityObserved(
            event: $event,
            result: $result,
            actorUserId: 42,
            targetUserId: 42,
            authenticationMethod: 'password',
            requestId: $requestId,
            networkKey: $networkKey,
            maskedIpAddress: $maskedIpAddress,
            clientKey: 'client-key',
            clientDescription: 'Firefox on Linux',
        );
    }

    /** @param array{database: bool, mail: bool} $channels */
    private function configureNotificationEvent(string $event, array $channels): void
    {
        $events = config('security.notifications.events', []);
        $this->assertIsArray($events);
        $events[$event] = $channels;
        config()->set('security.notifications.events', $events);
    }
}
