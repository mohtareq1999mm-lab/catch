# Frontend - Banner Feature

## Status

Admin SPA manages banners. Public/home page displays them.

## Consumption (Admin)

```javascript
export const bannerApi = {
  list(params)          // GET /api/v1/banners?limit=&active=
  create(formData)      // POST /api/v1/banners (multipart)
  show(id)              // GET /api/v1/banners/{id}
  update(id, formData)  // PUT /api/v1/banners/{id} (multipart)
  delete(id)            // DELETE /api/v1/banners/{id}
  changeStatus(id)      // PUT /api/v1/banner/change-status
  reorder(ids)          // POST /api/v1/banner/reorder
}
```

## Expected Frontend Components

```
BannersList.vue         → data table with drag-and-drop reorder, status toggle
BannerFormModal.vue     → create/edit with image upload, product selector
HomeBannerSlider.vue    → public display of active banners
```
