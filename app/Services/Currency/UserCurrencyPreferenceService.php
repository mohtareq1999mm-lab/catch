<?php

namespace App\Services\Currency;

use App\Models\Currency;
use App\Models\UserPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\User;

class UserCurrencyPreferenceService
{
    public function getUserPreference(?User $user): ?string
    {
        if (!$user) {
            return null;
        }

        $code = UserPreference::query()
            ->where('user_id', $user->getKey())
            ->value('currency_code');

        return $code ? strtoupper((string) $code) : null;
    }

    public function setUserPreference(User $user, string $currencyCode): void
    {
        UserPreference::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            ['currency_code' => strtoupper($currencyCode)],
        );
    }

    public function clearUserPreference(User $user): void
    {
        UserPreference::query()->where('user_id', $user->getKey())->delete();
    }

    /**
     * @deprecated Use header-based flow; kept for BC. Adopts X-Currency header value on login if user has no preference.
     */
    public function adoptGuestCurrencyOnLogin(User $user, ?Request $request = null): void
    {
        if (!Schema::hasTable('user_preferences')) {
            return;
        }
        $request ??= request();
        if ($this->getUserPreference($user) !== null) {
            return;
        }
        $headerCode = $this->getHeaderCurrencyCode($request);
        if ($headerCode === null || !$this->isValidActiveCurrency($headerCode)) {
            return;
        }
        $this->setUserPreference($user, $headerCode);
    }

    /**
     * Guest currency is now transported via X-Currency request header.
     * Frontend owns the value; backend validates and normalizes.
     */
    public function getHeaderCurrencyCode(?Request $request = null): ?string
    {
        $request ??= request();

        if (!$request) {
            return null;
        }

        $raw = $request->header('X-Currency');

        return $this->normalizeHeaderValue($raw);
    }

    public function isValidActiveCurrency(?string $currencyCode): bool
    {
        if (!$currencyCode) {
            return false;
        }

        return Currency::query()
            ->where('code', strtoupper($currencyCode))
            ->where('is_active', true)
            ->exists();
    }

    private function normalizeHeaderValue(mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || is_array($raw)) {
            return null;
        }

        $value = strtoupper(trim((string) $raw));

        return preg_match('/^[A-Z]{3}$/', $value) ? $value : null;
    }
}