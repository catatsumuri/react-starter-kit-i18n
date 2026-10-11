<?php

namespace Tests\Feature;

use App\Events\SecurityActivityObserved;
use App\Listeners\SecurityLogExportSubscriber;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class SecurityLogExportTest extends TestCase
{
    public function test_security_activity_is_exported_with_only_safe_structured_fields(): void
    {
        config()->set('security.export.enabled', true);
        config()->set('security.export.log_channel', 'security_export');

        Log::shouldReceive('channel')->once()->with('security_export')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('security_activity', $message);
            $this->assertSame(1, $context['schema_version']);
            $this->assertSame('authentication.login_succeeded', $context['event_name']);
            $this->assertSame('success', $context['result']);
            $this->assertSame(42, $context['target_user_id']);
            $this->assertSame('request-id', $context['request_id']);
            $this->assertSame('session-hmac', $context['session_reference']);
            $this->assertSame('network-hmac', $context['network_reference']);
            $this->assertSame('203.0.113.x', $context['ip_address']);
            $this->assertSame('Mozilla/5.0', $context['user_agent']);
            $this->assertArrayNotHasKey('password', $context);
            $this->assertArrayNotHasKey('session_id', $context);

            return true;
        });

        app(SecurityLogExportSubscriber::class)->export($this->observed());
    }

    public function test_export_failure_does_not_escape_the_listener(): void
    {
        config()->set('security.export.enabled', true);

        Log::shouldReceive('channel')->once()->andThrow(new RuntimeException('Unavailable'));
        Log::shouldReceive('error')->once();

        app(SecurityLogExportSubscriber::class)->export($this->observed());

        $this->assertTrue(true);
    }

    public function test_disabled_export_does_not_write_a_log(): void
    {
        config()->set('security.export.enabled', false);
        Log::shouldReceive('channel')->never();

        app(SecurityLogExportSubscriber::class)->export($this->observed());
    }

    private function observed(): SecurityActivityObserved
    {
        return new SecurityActivityObserved(
            event: 'authentication.login_succeeded',
            result: 'success',
            actorUserId: 42,
            targetUserId: 42,
            authenticationMethod: 'password',
            requestId: 'request-id',
            networkKey: 'network-hmac',
            maskedIpAddress: '203.0.113.x',
            clientKey: 'client-hmac',
            clientDescription: 'Firefox on Linux',
            metadata: ['origin' => 'web'],
            sessionFingerprint: 'session-hmac',
            userAgent: 'Mozilla/5.0',
        );
    }
}
