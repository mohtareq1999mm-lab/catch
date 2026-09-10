# Settings Module — Frontend Integration Guide

---

### 1. GET /api/v1/general/settings — Fetch Platform Settings (Public)

**Purpose:** Retrieve site-wide settings for rendering header, footer, SEO tags, and contact information.

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

---

## Frontend Usage

### SEO
Use `site_name`, `site_desc`, `meta_desc`, `favicon` for `<title>`, `<meta>`, and favicon tags on every page.

### Header / Footer
Use `logo` for the site logo image, `site_name` for branding text, and social links (`facebook`, `instagram`, `linkedin`, `youtube`) for footer icons.

### Contact
Use `site_email`, `email_support`, `phone` for contact section or "Reach Us" blocks.

### Promotional
Use `promotion_video_url` for hero section video backgrounds or promotional sections.

### Checkout Minimum
Use `minimumOrderAmount` to enforce minimum cart total before the user can place an order. If their cart total is below this value, show a message and disable the checkout button.

### Feature Flags
Use `fast_shipping_page_publish` to conditionally show/hide the fast shipping page link. Use `options` for arbitrary feature flags and configuration values.

### State Handling

| State | Behavior |
|-------|----------|
| **Loading** | Skeleton for header/footer placeholders |
| **Success** | Render all settings for header, footer, SEO, contact |
| **Error** | Fall back to hardcoded defaults (site name from env) |
| **Empty (null DB)** | Handle nullable fields with fallback values |
