<?php

namespace App\Providers;

use App\Actions\RecordSecurityEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorizationAuditing();
    }

    /**
     * Record every denied authorization check in the security log.
     *
     * The callback never returns a value, so it cannot alter the decision.
     */
    protected function configureAuthorizationAuditing(): void
    {
        Gate::after(function (?Authenticatable $user, string $ability, ?bool $result, array $arguments): void {
            if ($result !== false) {
                return;
            }

            $subject = collect($arguments)->first(fn (mixed $argument): bool => $argument instanceof Model);

            try {
                app(RecordSecurityEvent::class)(
                    'authorization.denied',
                    subject: $subject,
                    severity: 'warning',
                    context: [
                        'ability' => $ability,
                        'subject_type' => $subject?->getMorphClass(),
                        'subject_id' => $subject?->getKey(),
                        'authenticated' => $user !== null,
                    ],
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
