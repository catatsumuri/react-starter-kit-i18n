<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PasskeySecurityEventLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_created_passkey_is_recorded_without_its_name(): void
    {
        $user = User::factory()->create();
        $passkey = $this->createPasskey($user, 'My secret laptop');

        PasskeyRegistered::dispatch($user, $passkey);

        $event = Activity::inLog('security')->sole();

        $this->assertSame('passkey.created', $event->description);
        $this->assertTrue($event->subject->is($user));
        $this->assertSame($passkey->id, $event->getProperty('passkey_id'));
        $this->assertStringNotContainsString('My secret laptop', json_encode($event->properties));
    }

    public function test_a_passkey_that_was_not_created_is_not_recorded(): void
    {
        $user = User::factory()->create();
        Passkey::creating(fn () => false);

        try {
            $passkey = $this->createPasskey($user);
        } finally {
            Passkey::flushEventListeners();
        }

        PasskeyRegistered::dispatch($user, $passkey);

        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_deleting_a_passkey_records_the_event(): void
    {
        $user = User::factory()->create();
        $passkey = $this->createPasskey($user);

        $this->actingAsPasswordConfirmed($user)->delete(route('passkey.destroy', $passkey));

        $event = Activity::inLog('security')->sole();

        $this->assertSame('passkey.deleted', $event->description);
        $this->assertTrue($event->causer->is($user));
        $this->assertSame($passkey->id, $event->getProperty('passkey_id'));
        $this->assertModelMissing($passkey);
    }

    public function test_a_cancelled_passkey_delete_is_not_recorded(): void
    {
        $user = User::factory()->create();
        $passkey = $this->createPasskey($user);
        Passkey::deleting(fn () => false);

        try {
            $this->actingAsPasswordConfirmed($user)->delete(route('passkey.destroy', $passkey));
        } finally {
            Passkey::flushEventListeners();
        }

        $this->assertModelExists($passkey);
        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_deleting_another_users_passkey_is_forbidden_and_not_recorded(): void
    {
        $owner = User::factory()->create();
        $passkey = $this->createPasskey($owner);

        $this->actingAsPasswordConfirmed(User::factory()->create())
            ->delete(route('passkey.destroy', $passkey))
            ->assertForbidden();

        $this->assertModelExists($passkey);
        $this->assertSame(0, Activity::inLog('security')->count());
    }

    private function actingAsPasswordConfirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function createPasskey(User $user, string $name = 'Laptop'): Passkey
    {
        return $user->passkeys()->create([
            'name' => $name,
            'credential_id' => fake()->unique()->uuid(),
            'credential' => ['id' => 'x'],
        ]);
    }
}
