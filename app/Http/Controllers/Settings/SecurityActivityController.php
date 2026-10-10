<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ClientDescriptor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class SecurityActivityController extends Controller
{
    private const USER_VISIBLE_EVENTS = [
        'authentication.login_succeeded',
        'authentication.two_factor_confirmed',
        'authentication.two_factor_disabled',
        'authentication.recovery_codes_regenerated',
        'authentication.passkey_registered',
        'authentication.passkey_deleted',
        'account.password_changed',
        'account.email_changed',
        'session.revoked',
    ];

    public function __construct(private ClientDescriptor $clients) {}

    public function __invoke(Request $request): Response
    {
        $activities = Activity::query()
            ->where('log_name', 'security')
            ->where('subject_type', (new User)->getMorphClass())
            ->where('subject_id', $request->user()->getKey())
            ->whereIn('event', self::USER_VISIBLE_EVENTS)
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(function (Activity $activity): array {
                $properties = $activity->properties;
                $userAgent = $properties->get('user_agent');
                $client = $this->clients->describe(is_string($userAgent) ? $userAgent : null);
                $ipAddress = $properties->get('client_ip');
                $authenticationMethod = $properties->get('auth_method');

                return [
                    'id' => $activity->getKey(),
                    'event' => $activity->event,
                    'occurred_at' => $activity->created_at?->toISOString(),
                    'auth_method' => is_string($authenticationMethod) ? $authenticationMethod : null,
                    'device' => $client['browser'],
                    'platform' => $client['platform'],
                    'ip_address' => $this->clients->maskIpAddress(is_string($ipAddress) ? $ipAddress : null),
                ];
            });

        return Inertia::render('settings/security-activity', [
            'activities' => $activities,
        ]);
    }
}
