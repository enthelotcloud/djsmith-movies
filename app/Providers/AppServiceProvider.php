<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        // ----------------------------------------------------------------
        // 1. ROLE-BASED GATES
        // ----------------------------------------------------------------
        Gate::define('admin', function (User $user) {
            return $user->role === 'admin';
        });

        Gate::define('staff', function (User $user) {
            return in_array($user->role, ['admin', 'staff']);
        });

        Gate::define('client', function (User $user) {
            return $user->role === 'client';
        });

        // ----------------------------------------------------------------
        // 2. MOVIE ACCESS GATES (Subscription Logic)
        // ----------------------------------------------------------------
        
        // 🎬 Can they press PLAY?
        Gate::define('play-movie', function (User $user) {
            // Admins get a free pass
            if ($user->role === 'admin') return true;

            $sub = $user->currentSubscription;
            
            // Check if they have an active sub AND it has not expired
            return $sub 
                && $sub->status === 'active' 
                && $sub->expires_at 
                && now()->lessThanOrEqualTo($sub->expires_at);
        });

        // ⬇️ Can they press DOWNLOAD?
        Gate::define('download-movie', function (User $user) {
            if ($user->role === 'admin') return true;

            $sub = $user->currentSubscription;
            
            // Must have an active, unexpired plan that explicitly allows downloads
            return $sub 
                && $sub->status === 'active' 
                && $sub->expires_at 
                && now()->lessThanOrEqualTo($sub->expires_at)
                && $sub->plan
                && $sub->plan->can_download === true;
        });

        // ----------------------------------------------------------------
        // 3. RUN LARAVEL SYSTEM DEFAULTS
        // ----------------------------------------------------------------
        $this->configureDefaults();
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
            ? Password::min(6)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}