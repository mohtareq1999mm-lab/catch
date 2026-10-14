<?php

namespace Marvel\Database\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Enums\ShippingMethod;

class FastShippingRepository
{
    private const SETTINGS_KEY = 'fast_shipping';

    public function isGloballyEnabled(): bool
    {
        return (bool) data_get($this->getSettings(), 'enabled', false);
    }

    public function getDurationMinutes(): int
    {
        return (int) data_get($this->getSettings(), 'duration_minutes', 120);
    }

    public function getFee(): float
    {
        return (float) data_get($this->getSettings(), 'fee', 0);
    }

    public function getStartHour(): string
    {
        return (string) data_get($this->getSettings(), 'start_hour', '08:00');
    }

    public function getEndHour(): string
    {
        return (string) data_get($this->getSettings(), 'end_hour', '22:00');
    }

    public function isWithinWorkingHours(?Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now();
        $start = Carbon::parse($this->getStartHour());
        $end = Carbon::parse($this->getEndHour());

        return $now->between($start, $end);
    }

    public function isGovernorateEnabled(Governorate $governorate): bool
    {
        return $governorate->is_fast_shipping_enabled && $governorate->status;
    }

    public function areProductsFastEligible(Collection $cartItems): bool
    {
        if ($cartItems->isEmpty()) {
            return false;
        }

        $productIds = $cartItems->pluck('product_id')->unique()->toArray();
        $fastEligibleCount = Product::whereIn('id', $productIds)
            ->where('is_fast_shipping_available', true)
            ->count();

        return count($productIds) === $fastEligibleCount;
    }

    public function calculateEta(?Carbon $now = null): Carbon
    {
        $now = $now ?: Carbon::now();
        return $now->copy()->addMinutes($this->getDurationMinutes());
    }

    public function getSettings(): array
    {
        return Cache::remember('fast_shipping_settings', 3600, function () {
            $settings = Settings::query()->first();

            return data_get($settings, 'options.' . self::SETTINGS_KEY, $this->defaults());
        });
    }

    public function updateSettings(array $data): Settings
    {
        $settings = DB::transaction(function () use ($data) {
            $settings = Settings::query()->lockForUpdate()->first();
            $options = $settings->options ?? [];
            $options[self::SETTINGS_KEY] = array_merge($this->defaults(), $data);
            $settings->update(['options' => $options]);

            return $settings->fresh();
        });

        Cache::forget('fast_shipping_settings');

        return $settings;
    }

    public function getStatus(): array
    {
        $enabled = $this->isGloballyEnabled();
        $available = $enabled && $this->isWithinWorkingHours();

        $now = Carbon::now();
        $startHour = $this->getStartHour();
        $endHour = $this->getEndHour();

        $availableAgainAt = null;
        if (!$available && $enabled) {
            $start = Carbon::parse($startHour);
            $end = Carbon::parse($endHour);

            if ($now->gt($end)) {
                $availableAgainAt = $start->copy()->addDay()->format('H:i');
            } else {
                $availableAgainAt = $start->format('H:i');
            }
        }

        return [
            'enabled' => $enabled,
            'available' => $available,
            'duration_minutes' => $this->getDurationMinutes(),
            'fee' => $this->getFee(),
            'opens_at' => $startHour,
            'closes_at' => $endHour,
            'available_again_at' => $availableAgainAt,
        ];
    }

    public function validateCheckout(Governorate $governorate, Collection $cartItems): array
    {
        $errors = [];

        if (!$this->isGloballyEnabled()) {
            $errors[] = __('checkout.fast_shipping_unavailable');
        }

        if (!$this->isWithinWorkingHours()) {
            $errors[] = __('checkout.fast_shipping_hours_only', [
                'start' => $this->getStartHour(),
                'end' => $this->getEndHour(),
            ]);
        }

        if (!$this->isGovernorateEnabled($governorate)) {
            $errors[] = __('checkout.fast_shipping_governorate_unavailable');
        }

        if (!$this->areProductsFastEligible($cartItems)) {
            $errors[] = __('checkout.fast_shipping_items_ineligible');
        }

        if ($cartItems->isEmpty()) {
            $errors[] = __('checkout.cart_empty');
        }

        return $errors;
    }

    public function getNextAvailableTime(): ?string
    {
        if ($this->isGloballyEnabled() && !$this->isWithinWorkingHours()) {
            $now = Carbon::now();
            $start = Carbon::parse($this->getStartHour());
            $end = Carbon::parse($this->getEndHour());

            if ($now->gt($end)) {
                return $start->copy()->addDay()->format('H:i');
            }

            return $start->format('H:i');
        }

        return null;
    }

    private function defaults(): array
    {
        return [
            'enabled' => false,
            'duration_minutes' => 120,
            'fee' => 0,
            'start_hour' => '08:00',
            'end_hour' => '22:00',
        ];
    }
}
