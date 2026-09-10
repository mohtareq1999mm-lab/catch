# Frontend - Slider Feature

## Status

**No dedicated frontend Vue/React components** were found in `resources/js/`. The frontend is a separate SPA (likely Next.js, Nuxt, or similar) that consumes the slider APIs.

## Consumption Patterns

### 1. Homepage Hero Carousel

The primary frontend use case is displaying sliders as a rotating hero carousel/banner on the shop homepage.

```
GET /api/v1/general/sliders?limit=5

Response:
{
  "data": [
    {
      "id": 1,
      "title": "Summer Sale",
      "slug": "summer-sale",
      "status": true,
      "image": {
        "desktop": "https://cdn.example.com/sliders/summer-desktop.jpg",
        "mobile": "https://cdn.example.com/sliders/summer-mobile.jpg"
      },
      "products": [
        { "id": 1, "name": "Product 1", "slug": "product-1", ... }
      ]
    }
  ]
}
```

### 2. Content Page Sections (Puck Page Builder)

The `ContentPageSeeder` seeds a content page with:
- Block type: `'sliders'`, endpoint: `'sliders'`
- Front settings: `{ autoplay: true, slider_speed: 5000 }`

The `SectionTypeSettingSeeder` registers sliders as a section type with front settings for autoplay and speed control.

### 3. Slider Detail Page (Optional)

Slug-based lookup for individual slider display.

```
GET /api/v1/general/sliders/{slug}
```

## What a Frontend Implementation Would Need

### Public Components

```
HeroCarousel.vue (Homepage)
  Props: autoplay (bool, default: true), speed (number, default: 5000)
  Fetches: GET /api/v1/general/sliders
  Renders: rotating carousel with:
    - Desktop image (large screens)
    - Mobile image (small screens) — responsive <picture> or media query
    - Title overlay
    - Optional: link to category/product from associated products
    - Navigation dots/arrows
    - Auto-play with configurable speed
    - Pause on hover

SliderCard.vue (Reusable)
  Props: slider (object)
  Renders: single slide with image + optional text overlay
```

### Admin Components

```
AdminSliderListPage.vue
  Fetches: GET /api/v1/sliders (paginated, admin auth)
  Features:
    - Table with columns: title, slug, status, order, product count
    - Drag-and-drop reorder (calls PUT /api/v1/sliders/reorder)
    - Status toggle switch (calls PATCH /api/v1/sliders/change-status)
    - Edit button → AdminSliderEditPage
    - Delete button with confirmation
    - Create button → AdminSliderCreatePage

AdminSliderForm.vue
  Fields:
    - Title (multi-language tabs EN / AR / DE)
    - Desktop image upload (drag & drop, preview, jpeg/png/jpg/gif, max 2MB)
    - Mobile image upload (same constraints)
    - Status toggle
    - Product association (multi-select)
  Validation errors inline
  Image preview before upload
  Submit: POST /api/v1/sliders (create) or PUT /api/v1/sliders/{id} (update)

AdminSliderCreatePage.vue → contains AdminSliderForm
AdminSliderEditPage.vue → contains AdminSliderForm (pre-loads existing data)
```

### API Service Layer

```javascript
// services/sliderApi.js
export const sliderApi = {
  list(params)          // GET /api/v1/sliders
  show(id)             // GET /api/v1/sliders/{id}
  create(formData)     // POST /api/v1/sliders (multipart)
  update(id, formData) // PUT /api/v1/sliders/{id} (multipart)
  delete(id)           // DELETE /api/v1/sliders/{id}
  changeStatus(id)     // PATCH /api/v1/sliders/change-status
  reorder(ids)         // PUT /api/v1/sliders/reorder
  publicList(params)   // GET /api/v1/general/sliders
  publicBySlug(slug)   // GET /api/v1/general/sliders/{slug}
}
```

## Key Request/Response Examples

**Public Listing:**
```
GET /api/v1/general/sliders?limit=5&order=asc
Response: { data: [{ id, title, slug, status, image: { desktop, mobile }, products }] }
```

**Admin Create:**
```
POST /api/v1/sliders
Content-Type: multipart/form-data
Body:
  title[en]: "Summer Sale"
  title[ar]: "تخفيضات الصيف"
  image_desktop: <file>
  image_mobile: <file>
  status: 1
  products: [1, 2, 3]
```

**Reorder:**
```
PUT /api/v1/sliders/reorder
Body: { "sliders": [3, 1, 2] }  ← array of slider IDs in new order
```

**Change Status:**
```
PATCH /api/v1/sliders/change-status
Body: { "id": 5 }
```
