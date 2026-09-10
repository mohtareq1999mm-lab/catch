# Pages Module — Frontend Integration Guide

---

## FormData Handling (Critical)

When sending Section create/update as FormData (e.g., with file uploads or `_method=PUT` spoofing), the multilingual `title` field **must** be sent as:
```
title[en]: English Title
title[ar]: عنوان عربي
```
NOT as:
```
title: {"en":"English Title","ar":"عنوان عربي"}
```

The backend's `StoreSectionRequest` validates `title.*` as individual wildcard rules. Laravel's `excludeUnvalidatedArrayKeys` will drop the parent `title` key from `validated()`. The controller fix re-adds it from raw input, but only if sent in the `title[key]` format.

---

### 0. GET /api/v1/product-type — Product Type Labels (Public)

**Authentication:** Not required
**Locale:** Controlled via `lang` header (en, ar)

Returns an object mapping product type keys to their translated labels. Used by the page builder and section configuration to display human-readable section type names.

**Response 200:**
```json
{
  "best_product_sales": "Best Product Sales",
  "brands_product": "Brands Product",
  "new_arrivals": "New Arrivals",
  "all_product_discounts": "All Product Discounts",
  "product_discount_today_or_low_qty": "Product Discount Today or Low Quantity",
  "flash_sales_product": "Flash Sales Product",
  "flash_sales_end_today": "Flash Sales End Today",
  "product_for_parent_category": "Product for Parent Category",
  "flash_sales_end_week": "Flash Sales End Week"
}
```

---

### 1. GET /api/v1/general/content-pages — List Content Pages (Public)

**Authentication:** Not required

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "title": { "en": "Home Page", "ar": "الصفحة الرئيسية" },
        "slug": "home-page",
        "is_active": true,
        "sections": [
          {
            "id": 1,
            "type": "sliders",
            "title": "Main Slider",
            "is_active": true,
            "endpoint": "general/sliders?slug=home-slider",
            "order": 1,
            "setting": {
              "front": { "display": "carousel", "autoplay": true },
              "back": { "slug": "home-slider" }
            }
          }
        ]
      }
    ]
  }
}
```

---

### 2. GET /api/v1/general/content-pages/{slug} — Show Page (Public)

**Authentication:** Not required

**Response 200:** Same ContentPageResource structure.
**Response 404:** If slug not found.

---

### 3. Admin Endpoints

All admin endpoints require authentication + super_admin/editor role. The response structure for admin endpoints matches the same ContentPageResource / SectionResource format.

---

### 4. Page Rendering

The page frontend should:

1. **Fetch** the page by slug: `GET /api/v1/general/content-pages/{slug}`
2. **Iterate sections** in `sections` array (ordered by `order` field)
3. **For each section:**
   - Use `type` to determine which component to render (e.g., `sliders` → SliderCarousel, `banners` → BannerGrid, `categories` → CategoryList)
   - Use `setting.front` for display configuration (grid/carousel, columns, autoplay, etc.)
   - Fetch data from `endpoint` (e.g., `general/sliders?slug=home-slider`) to get the actual content
   - Render `title` only if it's a non-null value (title_visible flag)

### 5. Section Component Mapping

| Section Type | Suggested Component | Endpoint Example |
|-------------|-------------------|-----------------|
| sliders | SliderCarousel | `general/sliders?slug=home-slider` |
| banners | BannerGrid | `general/banners?slug=home-banner` |
| promotions | PromotionList | `general/promotions` |
| categories | CategoryList | `general/categories` |
| products | ProductGrid | `general/products?slug=featured` |
| flash-sales | FlashSaleCountdown | `general/flash-sales?slug=flash-deal` |
| brands | BrandCarousel | `general/brands` |
| coupons | CouponList | `general/coupons` |

### 6. State Handling

| State | Behavior |
|-------|----------|
| **Loading** | Skeleton page with section placeholders |
| **Empty** | Empty state message (e.g., "No content available") |
| **Error** | Toast with retry option |
| **Section loading** | Skeleton per section component |
| **Section error** | Show section error, continue rendering other sections |

### 7. Admin Page Builder

The admin should provide:
- **Page list:** Table with title, slug, status, actions (edit, toggle, delete)
- **Page editor:** Title input (multi-locale), active toggle, section management
- **Section attachment:** Multi-select or drag-and-drop to assign existing sections to page
- **Section editor:** Type selector, title input, active/title_visible toggles, settings editor (front/back JSON)
- **Section reorder:** Drag-and-drop list to set section order
- **Section type management:** CRUD interface for section types + settings editor
