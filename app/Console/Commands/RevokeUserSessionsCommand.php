<?php

namespace App\Console\Commands;

use App\Actions\Security\ResolveSecurityUser;
use App\Actions\Sessions\ManageDatabaseSessions;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class RevokeUserSessionsCommand extends Command
{
    /** @var string */
    protected $signature = 'security:revoke-sessions
        {selector : User selector: id:123 or email:user@example.com}
        {--force : Revoke sessions without interactive confirmation}';

    /** @var string */
    protected $description = 'Revoke every database session belonging to a user';

    public function handle(ResolveSecurityUser $users, ManageDatabaseSessions $sessions): int
    {
        $user = $this->resolveUser($users);

        if (! $user instanceof User) {
            return self::FAILURE;
        }

        if (! $sessions->isAvailable()) {
            $this->error('Session revocation requires the database session driver.');

            return self::FAILURE;
        }

        try {
            $storedSessionCount = $sessions->storedSessionCount($user);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Session storage is unavailable. No sessions were revoked.');

            return self::FAILURE;
        }

        if ($storedSessionCount === 0) {
            $this->info("No sessions are stored for {$user->name} ({$user->email}, ID {$user->getKey()}).");

            return self::SUCCESS;
        }

        $message = "Revoke {$storedSessionCount} session(s) for {$user->name} ({$user->email}, ID {$user->getKey()})?";

        if (! $this->option('force') && ! $this->confirm($message, false)) {
            $this->warn('No sessions were revoked.');

            return self::SUCCESS;
        }

        try {
            $revokedCount = $sessions->revokeAllSessions($user);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Session storage is unavailable. No sessions were revoked.');

            return self::FAILURE;
        }

        $this->info("Revoked {$revokedCount} session(s) for user ID {$user->getKey()}.");

        return self::SUCCESS;
    }

    private function resolveUser(ResolveSecurityUser $users): ?User
    {
        $selector = $this->argument('selector');

        try {
            $user = $users->handle(is_string($selector) ? $selector : '');
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return null;
        }

        if ($user === null) {
            $this->error('The specified user was not found.');
        }

        return $user;
    }
}
