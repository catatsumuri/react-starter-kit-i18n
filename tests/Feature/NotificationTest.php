<?php

namespace Tests\Feature;

use App\Actions\Notifications\SendGeneralNotification;
use App\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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
}
