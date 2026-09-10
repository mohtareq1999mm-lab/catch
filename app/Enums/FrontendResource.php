<?php

declare(strict_types=1);

namespace App\Enums;

enum FrontendResource: string
{
    case PRODUCTS = 'products';
    case ATTRIBUTES = 'attributes';
    case CATEGORIES = 'categories';
    case BRANDS = 'brands';
    case BRANDS_PRODUCTS = 'brands_products';
    case FLASH_SALES = 'flash_sales';
    case PROMOTIONS = 'promotions';
    case SETTINGS = 'settings';
    case COUPONS = 'coupons';
    case FAQS = 'faqs';
    case SLIDERS = 'sliders';
    case BANNERS = 'banners';
    case TAGS = 'tags';
    case CONTENT_PAGES = 'content_pages';
    case STATIC_PAGES = 'static_pages';
    case PICKUP_LOCATIONS = 'pickup_locations';
    case FAST_SHIPPING_SETTINGS = 'fast_shipping_settings';
    case SECTIONS = 'sections';
    case GOVERNORATES = 'governorates';
    case COUNTRIES = 'countries';
    case CITIES = 'cities';
    case ORDERS = 'orders';
    case DASHBOARD = 'dashboard';
    case SITE_REVIEWS = 'site_reviews';
    case CURRENCIES = 'currencies';
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}