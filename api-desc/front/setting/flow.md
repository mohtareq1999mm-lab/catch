# Request Flows — Settings Module (Public API)

## Flow 1: Fetch Settings — Success

```
Client → GET /api/v1/general/settings
         ↓
    [api] middleware group
         ↓
    SettingController@index
         ↓
    SettingService::getSetting()
         ↓
    Settings::first()
         ↓
    Single Settings model (or null)
         ↓
    SettingResource::make($setting)
         ↓
    Transform:
      site_name     → $this->getTranslation('site_name', app()->getLocale())
      site_desc     → $this->getTranslation('site_desc', app()->getLocale())
      meta_desc     → $this->getTranslation('meta_desc', app()->getLocale())
      site_copy_right → $this->getTranslation('site_copy_right', app()->getLocale())
      logo          → $this->getFirstMediaUrl('logo-setting')
      favicon       → $this->getFirstMediaUrl('favicon-setting')
      site_email    → $this->site_email
      email_support → $this?->email_support
      facebook      → $this?->facebook
      instagram     → $this?->instagram
      linkedin      → $this?->linkedin
      promotion_video_url → $this?->promotion_video_url
      youtube       → $this?->youtube
      phone         → $this?->phone
      fast_shipping_page_publish → $this->fast_shipping_page_publish
      minimumOrderAmount → $this->options['minimumOrderAmount'] ?? 0
      options       → $this->options
         ↓
    Response: 200
    {
      "status": 200,
      "message": "Data fetched successfully",
      "success": true,
      "data": { ... }
    }
```

## Flow 2: Fetch Settings — No DB Record

```
Client → GET /api/v1/general/settings
         ↓
    Settings::first() → null
         ↓
    SettingResource::make(null)
         ↓
    Response: 200
    {
      "status": 200,
      "message": "Data fetched successfully",
      "success": true,
      "data": {
        "site_name": null,
        "site_desc": null,
        "minimumOrderAmount": 0,
        ...
      }
    }
```
