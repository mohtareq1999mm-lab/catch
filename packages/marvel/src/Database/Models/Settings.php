<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Translatable\HasTranslations;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Settings extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    protected $table = 'settings';

    public $translatable = [
        'site_name',
        'site_desc',
        'meta_desc',
        'site_copy_right',
    ];

    public $fillable = [
        'site_name',
        'site_desc',
        'meta_desc',
        'site_copy_right',
        'logo',
        'footer_logo',
        'favicon',
        'site_email',
        'email_support',
        'facebook',
        'instagram',
        'linkedin',
        'promotion_video_url',
        'youtube',
        'tiktok',
        'snapchat',
        'phone',
        'fast_shipping_page_publish',
        'options',
        'minimum_order_amount',
        'order_tax_enabled',
        'order_tax_rate',
    ];

    protected $casts = [
        'options'              => 'array',
        'minimum_order_amount' => 'decimal:2',
        'order_tax_enabled' => 'boolean',
        'order_tax_rate' => 'float',
    ];

    public static function getData(string $language = null): ?self
    {
        $language = app()->getLocale();
        $cacheKey = 'cached_settings_' . $language;

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $settings = static::first();

        if ($settings) {
            Cache::put($cacheKey, $settings, 86400);
        }

        return $settings;
    }

}
