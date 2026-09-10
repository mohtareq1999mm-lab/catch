# Data Flow - Banner Feature

## Flow: Create Banner

```
Admin Client
  |
  POST /api/v1/banners
  Body: multipart (title.{en,ar}, description.{en,ar},
        image_desktop, image_mobile, products, status)
  Authorization: Bearer <token>
  |
  v
permission:CREATE_BANNERS middleware
  |
  v
BannerCreateRequest validation
  |  -- title.*: required, unique translation
  |  -- image_desktop: required, image, max 2MB
  |  -- products.*: exists:products,id
  |
  v
BannerRepository::createBanner($request)
  |  -- DB::beginTransaction
  |  -- Banner::create($request->except('image_*'))
  |  -- sync products
  |  -- uploadSingleImage (image_desktop → banners-desktop)
  |  -- uploadSingleImage (image_mobile → banners-mobile)
  |  -- DB::commit
  |
  v
BannerResource::make($banner->load('products'))
  |
  v
200 + Banner JSON
```

## Flow: Reorder

```
Admin Client
  |
  POST /api/v1/banner/reorder
  Body: { "banners": [3, 1, 2] }
  |
  v
permission:UPDATE_BANNERS middleware
  |
  v
BannerController@reorder($request)
  |  -- validate: banners is array, each exists
  |  -- BannerRepository::reorder([3, 1, 2])
  |       -> $this->setNewOrder([3, 1, 2])
  |          (Spatie Sortable updates order column)
  |
  v
200 + success
```

## Flow: Change Status (Toggle)

```
PUT /api/v1/banner/change-status
Body: { "id": 5 }
  |
  v
BannerController@changeStatus($request)
  |  -- validate: id exists
  |  -- BannerRepository::changeStatus(5)
  |       -> banner = find(5)
  |       -> update(['status' => !banner->status])
  |
  v
200 + BannerResource
```
