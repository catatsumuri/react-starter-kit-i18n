<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\SecurityNotification;
use App\Support\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;

/**
 * Specifies when a security notification email is sent.
 *
 * - Sent exactly once, and only after the operation actually succeeded.
 * - Never sent when the operation is rejected (wrong password, invalid code, ...).
 * - Delivery problems never fail or roll back the operation itself.
 * - The mail never carries secrets, tokens, links or the new email address.
 */
class SecurityEventNotificationTest extends TestCase
{
    use RefreshDatabase;

    // Password change

    public function test_password_change_notifies_the_user_once(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->updatePassword($user, 'password')->assertSessionHasNoErrors();

        $this->assertNotifiedOnce($user, SecurityEvent::PasswordChanged);
    }

    public function test_password_change_with_wrong_current_password_does_not_notify(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->updatePassword($user, 'wrong-password')->assertSessionHasErrors('current_password');

        Notification::assertNothingSent();
    }

    public function test_password_change_succeeds_even_when_notification_fails(): void
    {
        $user = User::factory()->create();
        $attempts = $this->failNotificationDelivery();

        $this->updatePassword($user, 'password')->assertSessionHasNoErrors();

        $this->assertDeliveryFailedWithoutAffectingOperation($attempts);
        $this->assertTrue(password_verify('new-password', $user->fresh()->password));
    }

    // Password reset

    public function test_password_reset_notifies_the_user_once(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->resetPassword($user, Password::createToken($user))->assertSessionHasNoErrors();

        $this->assertTrue(password_verify('new-password', $user->fresh()->password));
        $this->assertNotifiedOnce($user, SecurityEvent::PasswordReset);
    }

    public function test_password_reset_with_invalid_token_does_not_notify(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->resetPassword($user, 'invalid-token')->assertSessionHasErrors('email');

        $this->assertFalse(password_verify('new-password', $user->fresh()->password));
        Notification::assertNothingSent();
    }

    public function test_password_reset_with_invalid_password_does_not_notify(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.update'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'different-password',
        ])->assertSessionHasErrors('password');

        Notification::assertNothingSent();
    }

    public function test_requesting_a_password_reset_link_does_not_notify_as_a_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertNotSentTo($user, SecurityNotification::class);
    }

    // Two-factor authentication

    public function test_two_factor_with_confirmation_notifies_only_once_confirmed(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->post(route('two-factor.enable'));
        Notification::assertNothingSent();

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->post(route('two-factor.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors();
        Notification::assertNothingSent();

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->post(route('two-factor.confirm'), ['code' => $this->currentTwoFactorCode($user)])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->assertNotifiedOnce($user, SecurityEvent::TwoFactorEnabled);
    }

    public function test_two_factor_confirmation_that_is_not_saved_does_not_notify(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->post(route('two-factor.enable'));
        $code = $this->currentTwoFactorCode($user);

        $confirmedEvents = 0;
        Event::listen(TwoFactorAuthenticationConfirmed::class, function () use (&$confirmedEvents): void {
            $confirmedEvents++;
        });

        // Fortify fires its event even when the model save was cancelled.
        User::saving(fn () => false);

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->post(route('two-factor.confirm'), ['code' => $code]);

        $this->assertSame(1, $confirmedEvents, 'Fortify must have fired the confirmed event.');
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        Notification::assertNothingSent();
    }

    public function test_two_factor_without_confirmation_notifies_once_on_enable(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        Features::twoFactorAuthentication(['confirm' => false, 'confirmPassword' => true]);
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->post(route('two-factor.enable'));

        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertNotifiedOnce($user, SecurityEvent::TwoFactorEnabled);
    }

    public function test_disabling_two_factor_notifies_the_user_once(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        Notification::fake();
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->delete(route('two-factor.disable'));

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertNotifiedOnce($user, SecurityEvent::TwoFactorDisabled);
    }

    public function test_disabling_two_factor_that_was_never_enabled_does_not_notify(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->delete(route('two-factor.disable'));

        Notification::assertNothingSent();
    }

    public function test_regenerating_recovery_codes_notifies_the_user_once(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        Notification::fake();
        $user = User::factory()->withTwoFactor()->create();
        $previousCodes = $user->two_factor_recovery_codes;

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->post(route('two-factor.regenerate-recovery-codes'));

        $this->assertNotSame($previousCodes, $user->fresh()->two_factor_recovery_codes);
        $this->assertNotifiedOnce($user, SecurityEvent::RecoveryCodesRegenerated);
    }

    // Passkeys

    public function test_deleting_a_passkey_notifies_the_user_once(): void
    {
        $this->skipUnlessFortifyHas(Features::passkeys());
        Notification::fake();
        $user = User::factory()->create();
        $passkey = $this->createPasskey($user);

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->delete(route('passkey.destroy', $passkey));

        $this->assertModelMissing($passkey);
        $this->assertNotifiedOnce($user, SecurityEvent::PasskeyRemoved);
    }

    public function test_deleting_another_users_passkey_is_forbidden_and_does_not_notify(): void
    {
        $this->skipUnlessFortifyHas(Features::passkeys());
        Notification::fake();
        $user = User::factory()->create();
        $passkey = $this->createPasskey(User::factory()->create());

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->delete(route('passkey.destroy', $passkey))
            ->assertForbidden();

        $this->assertModelExists($passkey);
        Notification::assertNothingSent();
    }

    public function test_registering_an_invalid_passkey_does_not_notify(): void
    {
        $this->skipUnlessFortifyHas(Features::passkeys());
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->postJson(route('passkey.store'), [
                'name' => 'Laptop',
                'credential' => [
                    'id' => 'invalid',
                    'rawId' => 'invalid',
                    'type' => 'public-key',
                    'response' => ['clientDataJSON' => 'invalid', 'attestationObject' => 'invalid'],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, $user->passkeys()->count());
        Notification::assertNothingSent();
    }

    /**
     * A valid WebAuthn attestation cannot be forged in a test, so the package's
     * "registered" event is fired directly; what matters is whether the passkey
     * it carries was actually stored.
     */
    public function test_a_stored_passkey_registration_notifies_the_user_once(): void
    {
        $this->skipUnlessFortifyHas(Features::passkeys());
        Notification::fake();
        $user = User::factory()->create();

        event(new PasskeyRegistered($user, $this->createPasskey($user)));
        $this->runDeferredCallbacks();

        $this->assertNotifiedOnce($user, SecurityEvent::PasskeyAdded);
    }

    public function test_a_passkey_registration_event_for_an_unstored_passkey_does_not_notify(): void
    {
        $this->skipUnlessFortifyHas(Features::passkeys());
        Notification::fake();
        $user = User::factory()->create();

        event(new PasskeyRegistered($user, new Passkey));
        $this->runDeferredCallbacks();

        Notification::assertNothingSent();
    }

    // Email change

    public function test_email_change_notifies_only_the_old_address_once(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->changeEmail($user, 'new@example.com', 'password')->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $user->fresh()->email);
        Notification::assertCount(1);
        Notification::assertSentOnDemand(
            SecurityNotification::class,
            fn (SecurityNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notification->event === SecurityEvent::EmailChanged
                && $channels === ['mail']
                && $notifiable->routes === ['mail' => 'old@example.com'],
        );
        Notification::assertNotSentTo($user, SecurityNotification::class);
    }

    public function test_email_change_with_wrong_password_does_not_notify(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->changeEmail($user, 'new@example.com', 'wrong-password')->assertSessionHasErrors('current_password');

        $this->assertSame('old@example.com', $user->fresh()->email);
        Notification::assertNothingSent();
    }

    public function test_email_change_to_a_taken_address_does_not_notify(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->changeEmail($user, 'taken@example.com', 'password')->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_name_only_change_does_not_notify(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'New Name', 'email' => 'old@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('New Name', $user->fresh()->name);
        Notification::assertNothingSent();
    }

    public function test_email_change_succeeds_even_when_notification_fails(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $attempts = $this->failNotificationDelivery();

        $this->changeEmail($user, 'new@example.com', 'password')->assertSessionHasNoErrors();

        $this->assertDeliveryFailedWithoutAffectingOperation($attempts);
        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    // Account deletion

    public function test_account_deletion_notifies_the_deleted_address_once(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'gone@example.com']);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertModelMissing($user);
        Notification::assertCount(1);
        Notification::assertSentOnDemand(
            SecurityNotification::class,
            fn (SecurityNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notification->event === SecurityEvent::AccountDeleted
                && $notifiable->routes === ['mail' => 'gone@example.com'],
        );
    }

    public function test_account_deletion_with_wrong_password_does_not_notify(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        $this->assertModelExists($user);
        Notification::assertNothingSent();
    }

    public function test_account_deletion_succeeds_even_when_notification_fails(): void
    {
        $user = User::factory()->create();
        $attempts = $this->failNotificationDelivery();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertDeliveryFailedWithoutAffectingOperation($attempts);
        $this->assertModelMissing($user);
    }

    // Mail content

    public function test_no_notification_mail_contains_links_tokens_or_the_new_address(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        foreach (SecurityEvent::cases() as $event) {
            $mail = (new SecurityNotification($event))->toMail($user);
            $rendered = strtolower($mail->subject.' '.implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines));

            $this->assertNotEmpty($mail->subject, $event->name);
            $this->assertNull($mail->actionUrl, $event->name);
            $this->assertStringNotContainsString('http', $rendered, $event->name);
            $this->assertStringNotContainsString('token', $rendered, $event->name);
            $this->assertStringNotContainsString('@', $rendered, $event->name);
        }
    }

    // Helpers

    /**
     * @return array<string, int>
     */
    private function passwordConfirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    private function updatePassword(User $user, string $currentPassword)
    {
        return $this->actingAs($user)->withSession($this->passwordConfirmed())
            ->put(route('user-password.update'), [
                'current_password' => $currentPassword,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);
    }

    private function resetPassword(User $user, string $token)
    {
        return $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);
    }

    private function changeEmail(User $user, string $email, string $currentPassword)
    {
        return $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $email,
            'current_password' => $currentPassword,
        ]);
    }

    private function createPasskey(User $user): Passkey
    {
        return $user->passkeys()->forceCreate([
            'name' => 'Laptop',
            'credential_id' => fake()->uuid(),
            'credential' => [],
        ]);
    }

    private function currentTwoFactorCode(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    }

    private function runDeferredCallbacks(): void
    {
        app(DeferredCallbackCollection::class)->invoke();
    }

    /**
     * Assert exactly one notification was sent in total, by mail, for the given event, to the user.
     */
    private function assertNotifiedOnce(User $user, SecurityEvent $event): void
    {
        Notification::assertCount(1);
        Notification::assertSentTo(
            $user,
            SecurityNotification::class,
            fn (SecurityNotification $notification, array $channels) => $notification->event === $event
                && $channels === ['mail'],
        );
    }

    /**
     * Make every notification delivery throw, counting how often delivery was attempted.
     */
    private function failNotificationDelivery(): \ArrayObject
    {
        $attempts = new \ArrayObject;
        Exceptions::fake();
        Event::listen(NotificationSending::class, function () use ($attempts): void {
            $attempts[] = true;

            throw new RuntimeException('mail down');
        });

        return $attempts;
    }

    /**
     * Assert delivery was really attempted and failed, and the failure was only reported.
     *
     * @param  \ArrayObject<int, bool>  $attempts
     */
    private function assertDeliveryFailedWithoutAffectingOperation(\ArrayObject $attempts): void
    {
        $this->assertCount(1, $attempts, 'The notification must be attempted exactly once.');
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'mail down');
    }
}
