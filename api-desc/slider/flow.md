# Data Flow - Slider Feature

## Flow: Create Slider

```
Admin Client
  |
  POST /api/v1/sliders
  Body: multipart (title.{en,ar}, image_desktop, image_mobile, products, status)
  Authorization: Bearer <token>
  |
  v
permission:CREATE_SLIDER middleware
  |
  v
SliderCreateRequest validation
  |
  v
SliderRepository::createSlider($request)
  |  -- DB::beginTransaction
  |  -- Slider::create(...)
  |  -- uploadSingleImage (image_desktop → sliders-desktop)
  |  -- uploadSingleImage (image_mobile → sliders-mobile)
  |  -- sync products
  |  -- DB::commit
  |
  v
SliderResource::make($slider->load('products'))
  |
  v
200 + Slider JSON
```

## Flow: Reorder

```
PUT /api/v1/sliders/reorder
Body: { "sliders": [3, 1, 2] }
  |
  v
permission:UPDATE_SLIDER
  |
  v
SliderRepository::reorder([3, 1, 2])
  -> $this->setNewOrder([3, 1, 2])
     (Spatie Sortable updates order column)
  |
  v
200
```

## Flow: Change Status (Toggle)

```
PATCH /api/v1/sliders/change-status
Body: { "id": 5 }
  |
  v
SliderRepository::changeStatus(5)
  -> findOrFail(5)
  -> update(['status' => !$slider->status])
  |
  v
200 + SliderResource (with products loaded)
```
