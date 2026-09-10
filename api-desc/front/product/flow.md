# Data Flow - Product Feature

## Flow 1: Public Product Listing (Strategy-based)

```
Client
  |
  GET /api/v1/general/products?type=new_arrivals&category=electronics
  |
  v
General\ProductController@index(Request)
  |
  v
ProductService::paginate(Request)
  |
  +-- Determine strategy from ?type= parameter
  |     (default: "index" → AllProduct strategy)
  |
  +-- ProductStrategyResolver::resolve("new_arrivals")
  |     Maps to NewArrivals strategy class
  |
  +-- NewArrivals::execute()
  |     |-- Products created within last 15 days
  |     |-- No flash sale products
  |     |-- Active status
  |
  +-- Apply filters:
  |     |-- ProductFilter::applyFilters()
  |     |     |-- Brand filter (by name/slug)
  |     |     |-- Category filter (recursive descendants)
  |     |     |-- Promotion filter (by slug)
  |     |     |-- Flash sale filter (by slug/title)
  |     |     |-- Tag filter (AND logic)
  |     |     |-- Price range (product + variant)
  |     |     |-- Dimension range
  |     |     |-- Dynamic attributes (by slug)
  |
  +-- Apply search:
  |     |-- buildScoutSearchQuery() (Meilisearch)
  |     |-- OR buildFilteredBaseQuery() (SQL LIKE)
  |
  +-- Paginate:
  |     |-- limit (max 100, default 30)
  |     |-- sort by ID or price
  |
  +-- enrichCollectionWithPricing():
        |-- ProductPricingService::calculateProductPricing() for each
        |   - Flash Sale price > Discount price > Base price
        |   - current_price = final effective price
        |   - discount_active, flash_sale_active booleans
  |
  v
ProductMiniResource collection
  |-- Maps: id, name, slug, price, current_price,
  |         in_stock, discount_active, flash_sale_active,
  |         is_fast_shipping_available, ratings, image
  |
  v
JSON Response (paginated)
```

## Flow 2: Single Product Detail

```
Client
  |
  GET /api/v1/general/products/wireless-headphones
  |
  v
General\ProductController@getProductBySlug("wireless-headphones", Request)
  |
  v
ProductService::getProductBySlug("wireless-headphones", $limit)
  |
  +-- Product::where('slug', 'wireless-headphones')
  |     ->with(['categories', 'tags', 'brands',
  |             'variations', 'reviews.user', 'reviews.images',
  |             'flash_sales', 'promotions', 'digital_file',
  |             'manufacturer', 'author', 'shipping'])
  |     ->active()
  |     ->firstOrFail()
  |
  +-- enrichProductWithPricing($product)
  |     |-- ProductPricingService::calculateProductPricing()
  |     |-- Sets: current_price, discount_active, flash_sale_active
  |
  +-- getDynamicFilters($productQuery->getQuery())
  |     |-- Extract brands, categories, attributes, dimensions,
  |     |   ratings from the product set for filter sidebar
  |
  +-- ProductRepository::fetchRelated($product->id, $limit)
  |     |-- Find products in same categories
  |     |-- Exclude current product
  |     |-- Limit to $limit (default 10)
  |
  v
ProductResource (app)
  |-- Maps: id, name, slug, description, price, current_price,
  |         sku, in_stock, product_type, categories, brands,
  |         tags, images, variants, reviews, related_products,
  |         filters, discount_info, flash_sale_info
  |
  v
JSON Response
```

## Flow 3: Admin Product Creation

```
Client (Admin)
  |
  POST /api/v1/products
  Authorization: Bearer <token>
  Body (multipart): { name: { en, ar }, description: { en, ar },
                      product_type, price, categories, images, ... }
  |
  v
Middleware: permission:create-product
  |
  v
ProductController@store(ProductCreateRequest $request)
  |
  +-- ProductCreateRequest validation (30+ rules):
  |     |-- name (required, array)
  |     |-- name.* (string, max:255, unique_translation)
  |     |-- description (required, array, max:10000)
  |     |-- product_type (required, in:simple,variable)
  |     |-- categories (required, array)
  |     |-- images (required, array, mimes:jpeg,png,jpg, max:2048)
  |     |-- price (required_if:product_type=simple, numeric, min:0)
  |     |-- in_stock, has_discount, has_flash_sale (required)
  |     |-- variants.* (array of variant objects)
  |
  v
ProductRepository::storeProduct($request)
  |
  +-- DB::transaction():
  |     +-- Product::create($data)
  |           |-- Auto-generate SKU: "PRD-" . str_pad($id, 3, '0')
  |           |-- Set default status: "publish"
  |     |
  |     +-- syncRelation($product, $request, $data):
  |     |     |-- categories()->sync()
  |     |     |-- brands()->sync()
  |     |     |-- banners()->sync()
  |     |     |-- sliders()->sync()
  |     |     |-- tags()->sync()
  |     |     |-- flash_sales()->sync()
  |     |
  |     +-- Process images via Spatie Media Library:
  |     |     |-- $product->addMedia($image)->toMediaCollection()
  |     |
  |     +-- addVariants($product, $variants, $flashSale):
  |     |     |-- Create ProductVariant records
  |     |     |-- Create attribute_product pivot records
  |     |     |-- Calculate variant sale price
  |     |
  |     +-- Clear cache: "dashboard_product_analytics"
  |
  v
ProductResource (Marvel) response (201)
```

## Flow 4: Import Products (CSV)

```
Client (Admin)
  |
  POST /api/v1/import-products
  Body: { csv: (file), shop_id: 1 }
  |
  v
ProductImportController@import(ProductImportRequest)
  |
  +-- Validate: file (xlsx,xls,ods, max 20MB)
  |
  v
ImportProductsJob::dispatch($csvPath, $shop_id)
  |  (Queue: high, retries: 3, timeout: 1500s)
  |
  v
ProductImportService::import($csvPath, $shop_id)
  |
  +-- Parse CSV rows
  +-- For each row:
  |     |-- Create Product
  |     |-- Create Variants
  |     |-- Associate attributes
  |     |-- Download images from URLs
  |     |-- Track success/failure
  |     |-- Check cancellation signal
  |
  v
Import status: { total, success, failed, errors }
```

## Flow 5: Rental Price Calculation

```
Client
  |
  GET /api/v1/products/calculate-rental-price
  ?product_id=1&variation_id=2&from=2026-08-01&to=2026-08-05
  &quantity=2&persons=4
  &dropoff_location_id=1&pickup_location_id=2
  |
  v
ProductController@calculateRentalPrice(Request)
  |
  v
ProductRepository::calculatePrice($request)
  |
  +-- Resolve product + variant
  +-- Calculate base rental price (daily × days × quantity)
  +-- Check availability via Spatie Period
  |     |-- isProductAvailableAt($from, $to)
  |     |-- isVariationAvailableAt($from, $to)
  +-- Add dropoff/pickup location fees
  +-- Add deposit amounts
  +-- Add feature surcharges
  +-- Add person surcharges
  |
  v
Response: { "price_breakdown": { "base_price": 500,
              "location_fees": 50, "deposit": 100,
              "total": 650 } }
```
