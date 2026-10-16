<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\SecurityNotification;
use App\Support\SecurityEvent;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use RuntimeException;
use Tests\TestCase;

class SecurityEventNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function assertSentEvent(User $user, SecurityEvent $event): void
    {
        Notification::assertSentTo(
            $user,
            SecurityNotification::class,
            fn (SecurityNotification $notification) => $notification->event === $event,
        );
    }

    private function runDeferredCallbacks(): void
    {
        app(DeferredCallbackCollection::class)->invoke();
    }

    public function test_password_change_notifies_the_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSentEvent($user, SecurityEvent::PasswordChanged);
    }

    public function test_failed_password_change_does_not_notify(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasErrors('current_password');

        Notification::assertNothingSent();
    }

    public function test_password_change_succeeds_even_when_notification_fails(): void
    {
        $user = User::factory()->create();
        Event::listen(NotificationSending::class, fn () => throw new RuntimeException('mail down'));

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(password_verify('new-password', $user->fresh()->password));
    }

    public function test_password_reset_notifies_the_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        event(new PasswordReset($user));

        $this->runDeferredCallbacks();

        $this->assertSentEvent($user, SecurityEvent::PasswordReset);
    }

    public function test_two_factor_enabled_notifies_only_once_confirmed_when_confirmation_is_required(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        Features::twoFactorAuthentication(['confirm' => true]);

        event(new TwoFactorAuthenticationEnabled($user));

        $this->runDeferredCallbacks();
        Notification::assertNothingSent();

        event(new TwoFactorAuthenticationConfirmed($user));

        $this->runDeferredCallbacks();
        $this->assertSentEvent($user, SecurityEvent::TwoFactorEnabled);
    }

    public function test_two_factor_enabled_notifies_immediately_when_confirmation_is_not_required(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        Features::twoFactorAuthentication(['confirm' => false]);

        event(new TwoFactorAuthenticationEnabled($user));

        $this->runDeferredCallbacks();

        $this->assertSentEvent($user, SecurityEvent::TwoFactorEnabled);
    }

    public function test_two_factor_disabled_notifies_the_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        event(new TwoFactorAuthenticationDisabled($user));

        $this->runDeferredCallbacks();

        $this->assertSentEvent($user, SecurityEvent::TwoFactorDisabled);
    }

    public function test_recovery_code_regeneration_notifies_the_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        event(new RecoveryCodesGenerated($user));

        $this->runDeferredCallbacks();

        $this->assertSentEvent($user, SecurityEvent::RecoveryCodesRegenerated);
    }

    public function test_passkey_registration_and_deletion_notify_the_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $passkey = new Passkey;

        event(new PasskeyRegistered($user, $passkey));

        $this->runDeferredCallbacks();
        event(new PasskeyDeleted($user, $passkey));
        $this->runDeferredCallbacks();

        $this->assertSentEvent($user, SecurityEvent::PasskeyAdded);
        $this->assertSentEvent($user, SecurityEvent::PasskeyRemoved);
    }

    public function test_account_deletion_notifies_the_deleted_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'gone@example.com']);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertModelMissing($user);
        Notification::assertSentOnDemand(
            SecurityNotification::class,
            fn ($notification, $channels, $notifiable) => $notification->event === SecurityEvent::AccountDeleted
                && $notifiable->routes['mail'] === 'gone@example.com',
        );
    }

    public function test_failed_account_deletion_does_not_notify(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        Notification::assertNothingSent();
    }

    public function test_email_change_notifies_the_old_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'new@example.com',
                'current_password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $user->fresh()->email);
        Notification::assertSentOnDemand(
            SecurityNotification::class,
            fn ($notification, $channels, $notifiable) => $notification->event === SecurityEvent::EmailChanged
                && $notifiable->routes['mail'] === 'old@example.com',
        );
        Notification::assertSentOnDemandTimes(SecurityNotification::class, 1);
    }

    public function test_email_change_with_wrong_password_does_not_notify(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'new@example.com',
                'current_password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame('old@example.com', $user->fresh()->email);
        Notification::assertNothingSent();
    }

    public function test_name_only_change_does_not_notify(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'New Name',
                'email' => 'old@example.com',
            ])
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_email_change_succeeds_even_when_notification_fails(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        Event::listen(NotificationSending::class, fn () => throw new RuntimeException('mail down'));

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'new@example.com',
                'current_password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_email_change_notification_does_not_reveal_the_new_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'new-address@example.com',
                'current_password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(
            SecurityNotification::class,
            function (SecurityNotification $notification) {
                $mail = $notification->toMail(new AnonymousNotifiable);
                $rendered = $mail->subject.' '.implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines);

                $this->assertStringNotContainsString('new-address', $rendered);
                $this->assertStringNotContainsString('token', strtolower($rendered));
                $this->assertEmpty($mail->actionUrl ?? null);

                return true;
            },
        );
    }

    public function test_notification_mail_does_not_contain_secrets(): void
    {
        $user = User::factory()->create();

        $mail = (new SecurityNotification(SecurityEvent::RecoveryCodesRegenerated))->toMail($user);

        $this->assertStringNotContainsString($user->password, implode(' ', $mail->introLines));
        $this->assertNotEmpty($mail->subject);
    }
}
