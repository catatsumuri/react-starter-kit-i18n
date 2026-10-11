<?php

namespace App\Console\Commands;

use App\Actions\Security\ResolveSecurityUser;
use App\Actions\Sessions\ManageDatabaseSessions;
use App\Models\User;
use App\Support\ClientDescriptor;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class InspectUserSecurityCommand extends Command
{
    /** @var string */
    protected $signature = 'security:inspect-user
        {selector : User selector: id:123, email:user@example.com, or username:alice}
        {--limit=20 : Number of recent security activities to display (1-100)}';

    /** @var string */
    protected $description = 'Inspect a user, their active sessions, and recent security activity';

    public function handle(
        ResolveSecurityUser $users,
        ManageDatabaseSessions $sessions,
        ClientDescriptor $clients,
    ): int {
        $user = $this->resolveUser($users);

        if (! $user instanceof User) {
            return self::FAILURE;
        }

        $limit = $this->activityLimit();

        if ($limit === null) {
            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['ID', (string) $user->getKey()],
            ['Name', $user->name],
            ['Username', $user->username],
            ['Email', $user->email],
            ['Email verified', $user->email_verified_at === null ? 'no' : 'yes'],
        ]);

        if (! $this->displaySessionStatus($sessions, $user)) {
            return self::FAILURE;
        }

        return $this->displayActivities($user, $clients, $limit);
    }

    private function resolveUser(ResolveSecurityUser $users): ?User
    {
        try {
            $user = $users->handle((string) $this->argument('selector'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return null;
        }

        if ($user === null) {
            $this->error('The specified user was not found.');
        }

        return $user;
    }

    private function activityLimit(): ?int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);

        if (! is_int($limit)) {
            $this->error('The --limit option must be an integer between 1 and 100.');

            return null;
        }

        return $limit;
    }

    private function displaySessionStatus(ManageDatabaseSessions $sessions, User $user): bool
    {
        if (! $sessions->isAvailable()) {
            $this->warn('Session inspection is unavailable because the session driver is not database.');

            return true;
        }

        try {
            $count = $sessions->activeSessionCount($user);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Session storage is unavailable.');

            return false;
        }

        $this->info("Active database sessions: {$count}");

        return true;
    }

    private function displayActivities(User $user, ClientDescriptor $clients, int $limit): int
    {
        try {
            $activities = Activity::query()
                ->where('log_name', 'security')
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->getKey())
                ->latest()
                ->limit($limit)
                ->get();
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Security activity storage is unavailable.');

            return self::FAILURE;
        }

        if ($activities->isEmpty()) {
            $this->info('No security activity was found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Occurred at', 'Event', 'Result', 'Method', 'IP', 'Client', 'Request ID'],
            $activities->map(function (Activity $activity) use ($clients): array {
                $properties = $activity->properties;
                $ipAddress = $properties->get('client_ip');
                $userAgent = $properties->get('user_agent');
                $client = $clients->describe(is_string($userAgent) ? $userAgent : null);

                return [
                    $activity->created_at?->toISOString() ?? '-',
                    $activity->event ?? '-',
                    $this->stringProperty($properties->get('result')),
                    $this->stringProperty($properties->get('auth_method')),
                    $clients->maskIpAddress(is_string($ipAddress) ? $ipAddress : null),
                    implode(' on ', array_filter([$client['browser'], $client['platform']])),
                    $this->stringProperty($properties->get('request_id')),
                ];
            })->all(),
        );

        return self::SUCCESS;
    }

    private function stringProperty(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : '-';
    }
}
