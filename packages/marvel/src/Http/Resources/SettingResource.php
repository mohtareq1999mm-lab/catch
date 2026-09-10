<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class SettingResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
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
            'currency_selection_enabled' => (bool) data_get($this->options, 'currency_selection_enabled', false),
            'options' => $this->options ?? null,
        ];
    }
}