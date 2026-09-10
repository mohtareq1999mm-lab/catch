# Data Flow - Home Feature

## Flow 1: Home Page Data

```
Client
  |
  GET /api/v1/general/home?sections=sliders,brands,coupons
  (X-Channel: home)
  |
  v
ChannelMiddleware: sets ChannelContext = HOME
  |
  v
HomeController@index(Request)
  |
  v
HomeService::getHomeData($parentCategoryId = null, $sections = ['sliders','brands','coupons'])
  |
  +-- Default parent_category_id to 1 if null
  |
  +-- For each section requested:
  |     |-- Build cache key: "home:home-sliders"
  |     |-- Cache::remember(key, 7200 seconds):
  |     |     |-- getActiveSliders()
  |     |     |-- Slider::active()->ordered()->get()
  |     |     |-- SliderResource::collection()
  |
  +-- For product sections:
  |     |-- applyChannelHomeFilter() → WHERE is_fast_shipping_available = false
  |     |-- enrichCollectionWithPricing() → sets current_price
  |
  v
JSON Response with requested sections
```

## Flow 2: Nav Data

```
Client
  |
  GET /api/v1/general/nav-data?level=3
  |
  v
HomeController@navData(Request)
  |
  v
HomeService::getNavData(3)
  |
  +-- Cache key: "home:home-nav-bar:level:3"
  |
  +-- getCategoryWithChildren():
  |     |-- Root categories with up to 3 levels of children
  |     |-- Ordered by products_count DESC
  |     |-- CategoryNavbarResource::collection()
  |
  v
JSON Response (recursive category tree)
```
