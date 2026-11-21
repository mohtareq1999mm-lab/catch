<?php

namespace App\ValueObjects;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The explicit caller intent for every OrderTransitionService intent.
 *
 * ADR 0004 made the executor the single transition writer; this VO is the
 * actor-identity half of that contract: instead of reading ambient
 * `auth()` inside the executor, callers pass who moved the order (or
 * nobody — system, gateway, guest). Authority and audit fields derive from
 * the value, so the executor can reason without consulting session state.
 *
 * History rows keep the legacy values for existing flows: an authenticated
 * user is recorded as `admin` (carries payments.mark_paid or
 * update-order-status) or `user`; every other kind records `system` with a
 * null actor id.
 */
final class TransitionActor
{
    public const KIND_GUEST = 'guest';

    public const KIND_USER = 'user';

    public const KIND_ADMIN = 'admin';

    public const KIND_SYSTEM = 'system';

    public const KIND_GATEWAY = 'gateway';

    private function __construct(
        public readonly string $kind,
        public readonly ?Authenticatable $user = null,
    ) {
    }

    /** Nobody is responsible for the transition: workers, reapers, callbacks. */
    public static function system(): self
    {
        return new self(self::KIND_SYSTEM);
    }

    /** An anonymous caller on a guest flow: no session identity. */
    public static function guest(): self
    {
        return new self(self::KIND_GUEST);
    }

    /** A payment-provider callback: authority was established by verification. */
    public static function gateway(): self
    {
        return new self(self::KIND_GATEWAY);
    }

    /**
     * The authenticated/anonymous user actor. Anonymous resolves to the
     * system actor, preserving the legacy "no user session" history row;
     * authenticated users resolve to `admin` when they hold
     * payments.mark_paid or update-order-status, else `user` — the same
     * classification the executor used to derive from the session.
     */
    public static function user(?Authenticatable $user = null): self
    {
        if ($user === null) {
            return self::system();
        }

        return new self(self::userKind($user), $user);
    }

    /**
     * `admin` when the user holds payments.mark_paid or update-order-status
     * (the legacy history rule), `user` otherwise — the same classification
     * the executor used to derive from the authenticated user.
     */
    private static function userKind(Authenticatable $user): string
    {
        if (self::userHasAnyPermission($user, ['payments.mark_paid', 'update-order-status'])) {
            return self::KIND_ADMIN;
        }

        return self::KIND_USER;
    }

    /**
     * F-1 authority: an authenticated payer needs payments.mark_paid, exactly
     * as the legacy executor required it. Gateway and system carry authority
     * by construction (provider verification / background path); user and
     * admin need payments.mark_paid; a guest has none.
     */
    public function hasPaymentAuthority(): bool
    {
        return match ($this->kind) {
            self::KIND_GATEWAY, self::KIND_SYSTEM => true,
            self::KIND_USER, self::KIND_ADMIN => self::userHasAnyPermission($this->user, ['payments.mark_paid']),
            self::KIND_GUEST => false,
        };
    }

    /**
     * The force-deliver escape hatch needs the legacy update-order-status
     * permission, exactly as the legacy executor required it.
     */
    public function canForceDeliver(): bool
    {
        return self::userHasAnyPermission($this->user, ['update-order-status']);
    }

    public function historyActorId(): ?int
    {
        return $this->user?->getAuthIdentifier();
    }

    public function historyActorType(): string
    {
        if ($this->user === null) {
            return 'system';
        }

        return match ($this->kind) {
            self::KIND_ADMIN => 'admin',
            default => 'user',
        };
    }

    /**
     * Best-effort permission check on the user model. The executor used to
     * probe method_exists + hasAnyPermission/hasPermissionTo/can and treat
     * failures as "no permission"; the actor preserves that contract.
     */
    private static function userHasAnyPermission(?Authenticatable $user, array $permissions): bool
    {
        if ($user === null) {
            return false;
        }

        try {
            if (method_exists($user, 'hasAnyPermission')) {
                return (bool) $user->hasAnyPermission($permissions);
            }

            if (method_exists($user, 'hasPermissionTo')) {
                return array_reduce(
                    $permissions,
                    fn (bool $acc, string $permission) => $acc || (bool) $user->hasPermissionTo($permission),
                    false,
                );
            }

            if (method_exists($user, 'can')) {
                return array_reduce(
                    $permissions,
                    fn (bool $acc, string $permission) => $acc || (bool) $user->can($permission),
                    false,
                );
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }
}
