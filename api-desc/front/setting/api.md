# API Reference — Settings Module (Public API)

---

### GET /api/v1/general/settings

Fetch platform settings. Returns a singleton settings object with site information, SEO metadata, social links, contact details, and media URLs.

**Authentication:** None (public)

**Query Parameters:** None

**Response 200:**
```json
{
    "status": 200,
    "message": "تم جلب البيانات بنجاح",
    "success": true,
    "data": {
        "site_name": "موقعي",
        "site_desc": "هذا هو وصف الموقع.",
        "meta_desc": "الوصف التعريفي للموقع.",
        "site_copy_right": "© 2026 جميع الحقوق محفوظة.",
        "logo": "",
        "favicon": "",
        "site_email": "info@example.com",
        "email_support": "support@example.com",
        "facebook": "https://facebook.com/mywebsite",
        "instagram": "https://instagram.com/mywebsite",
        "linkedin": "https://linkedin.com/company/mywebsite",
        "promotion_video_url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
        "youtube": "https://youtube.com/@mywebsite",
        "phone": "+201001234567",
        "fast_shipping_page_publish": 1,
        "minimumOrderAmount": 100,
        "options": {
            "minimumOrderAmount": 100,
            "fast_shipping": {
                "enabled": true,
                "duration_minutes": 120,
                "fee": 0,
                "start_hour": "08:00",
                "end_hour": "22:00"
            }
        }
    }
}
```

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/general/settings" \
  -H "Accept: application/json"
```

**Business Rules:**
- Returns exactly one record (singleton pattern)
- Translatable fields returned in request locale (Accept-Language header)
- Media URLs are returned from Spatie Media Library collections (`logo-setting`, `favicon-setting`)
- `minimumOrderAmount` exposed at top level (also inside `options`); used by checkout to enforce minimum order total
- `options` field is free-form JSON for arbitrary configuration
- No pagination needed (single object)
