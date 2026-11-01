<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class SettingResource extends Resource
{
    /**
     * Per-gateway enable/availability state for the Site Settings responses
     * (rendered on both the public and admin GET settings endpoints).
     *
     * Source of truth: the same GatewaySettingsService merge the admin
     * payment-gateway API and checkout use — {code: {enabled, available}}.
     * available = enabled && configured && supports_catalog_currency
     * (display layer; checkout HTTP 422 stays the authority). Secrets are
     * never included. Fault-tolerant: returns [] if the payment subsystem
     * or currency catalog cannot resolve.
     */
    private function gatewayStates(): array
    {
        try {
            return app(\App\Services\Payment\GatewaySettingsService::class)->clientAvailabilityMap();
        } catch (\Throwable $e) {
            // Fault tolerance for the settings payload: degrade to {} but
            // leave a trace — a silent swallow would hide wiring bugs.
            \Illuminate\Support\Facades\Log::warning(
                'SettingResource: payment_gateways availability map unavailable.',
                ['exception' => $e]
            );

            return [];
        }
    }

    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        // Null invariant: Settings::first() can return null when the settings table is empty
        // (e.g. fresh DB, truncated table, stale cache). Do not throw getTranslation() on null.
        if (!$this->resource) {
            return [
                "site_name" => request()->routeIs('settings.front') ? null : ['ar' => null, 'en' => null],
                "site_desc" => request()->routeIs('settings.front') ? null : ['ar' => null, 'en' => null],
                "meta_desc" => request()->routeIs('settings.front') ? null : ['ar' => null, 'en' => null],
                "site_copy_right" => request()->routeIs('settings.front') ? null : ['ar' => null, 'en' => null],
                "logo" => null,
                "footer_logo" => null,
                "favicon" => null,
                "site_email" => null,
                "email_support" => null,
                "facebook" => null,
                "instagram" => null,
                "linkedin" => null,
                "promotion_video_url" => null,
                'youtube' => null,
                'tiktok' => null,
                'snapchat' => null,
                'phone' => null,
                'fast_shipping_page_publish' => null,
                'minimumOrderAmount' => null,
                'order_tax_enabled' => false,
                'order_tax_rate' => null,
                'currency_selection_enabled' => false,
                'options' => null,
                'payment_gateways' => $this->gatewayStates(),
            ];
        }

        return [
            "site_name" => request()->routeIs('settings.front') ? $this->getTranslation('site_name', app()->getLocale()) : [
                'ar' => $this->getTranslation('site_name', 'ar'),
                'en' => $this->getTranslation('site_name', 'en'),
            ],
            "site_desc" => request()->routeIs('settings.front') ? $this->getTranslation('site_desc', app()->getLocale()) : [
                'ar' => $this->getTranslation('site_desc', 'ar'),
                'en' => $this->getTranslation('site_desc', 'en'),
            ],
            "meta_desc" => request()->routeIs('settings.front') ? $this->getTranslation('meta_desc', app()->getLocale()) : [
                'ar' => $this->getTranslation('meta_desc', 'ar'),
                'en' => $this->getTranslation('meta_desc', 'en'),
            ],
            "site_copy_right" => request()->routeIs('settings.front') ? $this->getTranslation('site_copy_right', app()->getLocale()) : [
                'ar' => $this->getTranslation('site_copy_right', 'ar'),
                'en' => $this->getTranslation('site_copy_right', 'en'),
            ],
            "logo" => $this->getFirstMediaUrl('logo-setting'),
            "footer_logo" => $this->getFirstMediaUrl('footer_logo-setting'),
            "favicon" => $this->getFirstMediaUrl('favicon-setting'),
            "site_email" => $this->site_email,
            "email_support" => $this?->email_support,
            "facebook" => $this?->facebook,
            "instagram" => $this?->instagram,
            "linkedin" => $this?->linkedin,
            "promotion_video_url" => $this?->promotion_video_url,
            'youtube' => $this?->youtube,
            'tiktok' => $this?->tiktok,
            'snapchat' => $this?->snapchat,
            'phone' => $this?->phone,
            'fast_shipping_page_publish' => $this->fast_shipping_page_publish,
            'minimumOrderAmount' => $this->minimum_order_amount,
            'order_tax_enabled' => (bool) $this->order_tax_enabled,
            'order_tax_rate' => $this->order_tax_rate !== null ? (float) $this->order_tax_rate : null,
            'currency_selection_enabled' => (bool) data_get($this->options, 'currency_selection_enabled', false),
            'payment_gateways' => $this->gatewayStates(),
            'options' => $this->options ?? null,
        ];
    }
}