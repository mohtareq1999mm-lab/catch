# Frontend - Slider Feature

## Consumption (Admin)

```javascript
export const sliderApi = {
  list(params)          // GET /api/v1/sliders?active=&order=&sortedBy=&per_page=
  create(formData)      // POST /api/v1/sliders (multipart)
  show(id)              // GET /api/v1/sliders/{id}
  update(id, formData)  // PUT /api/v1/sliders/{id} (multipart)
  delete(id)            // DELETE /api/v1/sliders/{id}
  changeStatus(id)      // PATCH /api/v1/sliders/change-status
  reorder(ids)          // PUT /api/v1/sliders/reorder
}
```

## Consumption (Public)

```javascript
export const publicSliderApi = {
  list(slug)            // GET /api/v1/general/sliders?slug=
  show(slug)            // GET /api/v1/general/sliders/{slug}
}
```

## Expected Frontend Components

```
SlidersList.vue           → admin table with drag-and-drop reorder, status toggle
SliderFormModal.vue       → admin create/edit with image upload, product selector
HomeSliderCarousel.vue    → public display of active sliders
```
