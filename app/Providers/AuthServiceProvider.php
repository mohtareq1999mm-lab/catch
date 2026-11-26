<?php

namespace App\Providers;

use App\Models\Shipping\ShipmentAssignment;
use App\Models\Finance\FinanceAccessGrant;
use App\Policies\ShipmentAssignmentPolicy;
use App\Support\FinanceAccess;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        \Marvel\Database\Models\Import::class => \App\Policies\ImportPolicy::class,
        ShipmentAssignment::class => ShipmentAssignmentPolicy::class,
    ];

    /**
     * Register authorization hooks before package providers register their own
     * permission gates in boot(), so expiry can deny before Spatie grants.
     */
    public function register(): void
    {
        // #191: finance permissions are valid only through the dedicated role
        // or a live break-glass grant. Returning null defers to Spatie; false
        // denies expired/revoked direct grants without pivot cleanup.
        Gate::before(function ($user, string $ability): ?bool {
            if (! in_array($ability, FinanceAccess::permissions(), true)) {
                return null;
            }

            if (method_exists($user, 'hasRole') && $user->hasRole(FinanceAccess::ROLE)) {
                return null;
            }

            $activeGrant = FinanceAccessGrant::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('permission', $ability)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            return $activeGrant ? null : false;
        });
    }

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();
    }
}
