<?php

namespace App\Providers;

use App\Enums\Module;
use App\Enums\PermissionAction;
use App\Models\User;
use App\Services\Permissions\ModulePermissions;
use App\Services\Settings\SettingsService;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(ModulePermissions::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // HTTPS is enforced at all times outside local dev (SECURITY.md §7).
        if (! $this->app->environment('local', 'testing')) {
            URL::forceScheme('https');
        }

        $this->registerModuleGates();
    }

    /**
     * One Gate ability per module × action ("employees.view", "payroll.approve", …).
     * Super Admin passes everything via Gate::before; Company Admins and custom
     * users resolve through ModulePermissions (SECURITY.md §3).
     */
    private function registerModuleGates(): void
    {
        Gate::before(function (User $user): ?bool {
            if (! $user->active) {
                return false;
            }

            return $user->isSuperAdmin() ? true : null;
        });

        $permissions = fn (): ModulePermissions => $this->app->make(ModulePermissions::class);

        foreach (Module::cases() as $module) {
            foreach (PermissionAction::cases() as $action) {
                Gate::define(
                    ModulePermissions::ability($module, $action),
                    fn (User $user): bool => $permissions()->allows($user, $module, $action),
                );
            }
        }
    }
}
