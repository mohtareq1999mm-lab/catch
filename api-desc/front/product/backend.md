# Backend - Product Feature

## Overview

The Product feature is the most complex feature in the system, spanning three layers:

1. **App Layer (`app/`)**: Public API — strategy-based product listing, advanced filtering, full-text search, reviews
2. **Package Layer (`packages/marvel/`)**: Admin CRUD with permissions, pricing engine, import/export, GraphQL, variants
3. **Meem Integration**: Separate simple product model for Meem platform

## Key Files

### 1. Model - `packages/marvel/src/Database/Models/Product.php`

**Table:** `products`

**Traits:** `HasTranslations` (Spatie), `SoftDeletes`, `InteractsWithMedia` (Spatie), `Searchable` (Scout), `Excludable`

**Translatable:** `['name', 'description']`

**Fillable (45+ columns):**
- `name`, `slug`, `description`, `price`, `product_type`, `type_id`, `sku`, `stock_quantity`, `quantity`, `reserved_quantity`, `sold_quantity`, `in_stock`, `status`, `height`, `width`, `length`, `weight`, `has_flash_sale`, `is_fast_shipping_available`, `has_discount`, `pieces`, `discount_type`, `discount_amount`, `discount_status`, `start_date`, `end_date`, `price_after_discount`, `price_after_flash_sale`

**Appended Attributes:**
- `current_price` (from ProductPricingService)
- `price_after_discount`, `price_after_flash_sale`, `final_price`
- `available_stock` — `max(0, stock_quantity - reserved_quantity)`
- `ratings`, `total_reviews`, `rating_count`
- `my_review`, `in_wishlist`

**Relationships (20+):**

| Method | Type | Related |
|--------|------|---------|
| `type()` | `BelongsTo` | `Type` |
| `shops()` | `BelongsToMany` | `Shop` (pivot: `product_shop`) |
| `author()` | `BelongsTo` | `Author` |
| `manufacturer()` | `BelongsTo` | `Manufacturer` |
| `shipping()` | `BelongsTo` | `Shipping` |
| `categories()` | `BelongsToMany` | `Category` (pivot: `category_product`) |
| `brands()` | `BelongsToMany` | `Brand` (pivot: `brand_product`) |
| `tags()` | `BelongsToMany` | `Tag` (pivot: `product_tag`) |
| `variations()` | `HasMany` | `ProductVariant` |
| `reviews()` | `HasMany` | `Review` |
| `orders()` | `BelongsToMany` | `Order` (pivot: `order_product`) |
| `flash_sales()` | `BelongsToMany` | `FlashSale` (pivot: `flash_sale_products`) |
| `promotions()` | `BelongsToMany` | `Promotion` (pivot: `promotion_product`) |
| `coupons()` | `BelongsToMany` | `Coupon` (pivot: `coupon_product`) |
| `sliders()` | `BelongsToMany` | `Slider` (pivot: `slider_product`) |
| `banners()` | `BelongsToMany` | `Banner` (pivot: `banner_product`) |
| `wishlists()` | `HasMany` | `Wishlist` |
| `questions()` | `HasMany` | `Question` |
| `digital_file()` | `MorphOne` | `DigitalFile` |
| `availabilities()` | `MorphMany` | `Availability` |

**Scopes:**
- `active()` — status=publish/true AND stock available
- `activeStatus()` — status=true/publish
- `fastShippingAvailable()` — `is_fast_shipping_available = true`
- `search($field, $term, $locale)` — Translatable LIKE search
- `filter($filters)` — Delegates to `ProductFilter` service

**Global Scopes:**
- `FastShippingScope` — channel-based filtering

### 2. Repository - `packages/marvel/src/Database/Repositories/ProductRepository.php`

**Extends:** `BaseRepository`

**Uses:** `MediaManager` trait

| Method | Description |
|--------|-------------|
| `storeProduct($request)` | Creates product with variants, images, relations in transaction. Clears dashboard analytics cache. |
| `updateProduct($request, $id)` | Full re-sync of variants, images, relations |
| `syncRelation($product, $request, $data)` | Syncs categories, brands, banners, sliders, tags, flash_sales |
| `addVariants($product, $variants, $flashSale)` | Creates ProductVariant + AttributeProduct with sale price |
| `fetchRelated($id, $limit)` | Related by same categories |
| `getBestSellingProducts($request)` | LEFT JOIN order_product + orders |
| `calculatePrice()` | Full rental price calculation |
| `resolveFlashSale()` | Gets valid flash sale for product |

### 3. Controller (Admin) - `packages/marvel/src/Http/Controllers/ProductController.php`

**Permissions (via constructor middleware):**

| Method | Permission |
|--------|-----------|
| `index`, `show` | `view-products` |
| `store` | `create-product` |
| `update` | `update-product` |
| `destroy`, `destroyBulk`, `destroyAll` | `delete-product` |

**Key Methods:**
- `index(Request)` — Paginated list with sorting, advanced filtering
- `store(ProductCreateRequest)` — Creates via `ProductRepository::storeProduct()`
- `show(Request, $id)` — Single product with all relations
- `update(ProductUpdateRequest, $id)` — Full update with re-sync
- `destroy(Request, $id)` — Soft delete
- `destroyBulk(BulkDeleteProductsRequest)` — Bulk soft delete
- `destroyAll()` — Chunked delete all
- `bestSellingProducts(Request)` — Public best sellers
- `popularProducts(Request)` — Public popular products
- `calculateRentalPrice(Request)` — Public rental calculator
- `toggleFastShipping(Request, $id)` — Toggle flag
- `draftedProducts(Request)` — Draft products for vendor
- `productStock(Request)` — Low stock (<10)

### 4. Controller (Public) - `app/Http/Controllers/Api/General/ProductController.php`

| Method | Description |
|--------|-------------|
| `index(Request)` | Strategy-based product listing via ProductEngine |
| `getProductBySlug(Request, $slug)` | Single product with reviews, related, filters |
| `addProductReview(ReviewCreateRequest, $id)` | Add review with images |
| `updateProductReview(ReviewUpdateRequest, $id)` | Update own review |

### 5. Service (Public) - `app/Services/General/ProductService.php`

| Method | Description |
|--------|-------------|
| `buildFilteredBaseQuery(Request)` | Active query with relations, filters |
| `buildScoutSearchQuery(Request)` | Meilisearch full-text search |
| `paginate(Request)` | Paginated with pricing enrichment |
| `paginateFlashSales(Request)` | Flash sale products |
| `getProductBySlug($slug, $limit)` | Single with related |
| `getBestProductSales(Request)` | Top sellers |
| `getNewArrivals(Request)` | Last 15 days |
| `getAllDiscountProducts(Request)` | Active discounts |
| `enrichProductWithPricing(Product)` | Sets current_price |
| `getDynamicFilters(Builder)` | Builds filter array |

### 6. ProductEngine (Strategy) - `app/Services/General/ProductEngine/`

**Interface:** `Contract/ProductTypeStrategy`

**Resolver:** `ProductStrategyResolver` — Maps type parameter to strategy class

**Strategies (10):**
- `AllProduct` — Paginate all
- `BestProduct` — Best sellers
- `NewArrivals` — Last 15 days
- `AllProductHasDiscount` — Active discounts
- `ProductDiscountEndingTodayOrLowStock` — Badged products
- `ProductForBrand` — By brand
- `ProductForParentCategory` — By parent category
- `ProductHasFlashSale` — Flash sale products
- `ProductHasFlashSaleEndToday` — Ending today
- `ProductHasFlashSaleEndThisWeek` — Ending this week

### 7. Form Requests

**ProductCreateRequest:**
- `name` (required, array), `name.*` (string, max:255, unique_translation)
- `description` (required, array), `description.*` (string, max:10000)
- `price` (sometimes, numeric, min:0, required_if:product_type=simple)
- `product_type` (required, in:simple,variable)
- `categories` (required, array), `categories.*` (integer, exists:categories,id)
- `images` (required, array), `images.*` (file, mimes:jpeg,png,jpg, max:2048)
- `in_stock` (required, boolean)
- `has_discount` (required, boolean)
- `has_flash_sale` (required, boolean)
- Plus 30+ optional fields

**ProductUpdateRequest:**
- All fields `sometimes` (optional)
- Additional: `shop_id`, `tags`, `variants.*.id`, `variants.*.sale_price`

### 8. API Resources

| Resource | Fields |
|----------|--------|
| `ProductResource` (Marvel/Admin) | Full product with all relations, variants, images |
| `ProductResource` (App/Public Detail) | Product with reviews, related, filters, pricing |
| `ProductMiniResource` (App/Public List) | Compact: id, name, slug, price, current_price, ratings, image |
| `ProductVariantResource` | id, price, stock, attributes |
| `RelatedProductResource` | Basic related product info |

### 9. Permissions - `packages/marvel/src/Enums/Permission.php`

| Constant | Value |
|----------|-------|
| `VIEW_PRODUCTS` | `view-products` |
| `VIEW_PRODUCT` | `view-product` |
| `CREATE_PRODUCT` | `create-product` |
| `UPDATE_PRODUCT` | `update-product` |
| `DELETE_PRODUCT` | `delete-product` |
| `VIEW_LOW_STOCK_PRODUCTS` | `view-low-stock-products` |
| `VIEW_DRAFT_PRODUCTS` | `view-draft-products` |

### 10. Enums

**ProductType:** `simple`, `variable`

**ProductStatus:** `under_review`, `approved`, `rejected`, `publish`, `unpublish`, `draft`

**ProductVisibilityStatus:** `visibility_private`, `visibility_public`, `visibility_protected`

### 11. Config Constants - `packages/marvel/config/constants.php`

| Constant | Message Key |
|----------|-------------|
| `CREATE_PRODUCT_SUCCESSFULLY` | `MESSAGE.CREATE_PRODUCT_SUCCESSFULLY` |
| `UPDATE_PRODUCT_SUCCESSFULLY` | `MESSAGE.UPDATE_PRODUCT_SUCCESSFULLY` |
| `DELETE_PRODUCT_SUCCESSFULLY` | `MESSAGE.DELETE_PRODUCT_SUCCESSFULLY` |
| `PRODUCTS_DELETED_SUCCESSFULLY` | `MESSAGE.PRODUCTS_DELETED_SUCCESSFULLY` |

### 12. GraphQL

**Schema:** `packages/marvel/src/GraphQL/Schema/models/product.graphql`

**Type fields:** 50+ fields including id, name, slug, type, categories, tags, variations, reviews, pricing, images, gallery, video, ratings, shop, author, manufacturer, digital_file, related_products

**Queries:**
- `products(search, date_range, orderBy, sortedBy, language, product_type, status, shop_id, ...)` — Paginated
- `product(id, slug, language)` — @find
- `productsStock(...)` — Stock query
- `productsDraft(...)` — Draft query

**Mutations:**
- `createProduct(input: CreateProductInput!)` — `ProductMutator@storeProduct`
- `updateProduct(input: UpdateProductInput!)` — `ProductMutator@updateProduct`
- `deleteProduct(id: ID!)` — `ProductMutator@deleteProduct`
- `calculateRentalPrice(input)` — `ProductMutator@calculateRentalPrice`
- `importProducts(shop_id, csv)` — CSV import
- `importVariationOptions(shop_id, csv)` — Variant CSV import

### 13. Pricing Service - `packages/marvel/src/Services/Pricing/ProductPricingService.php`

| Method | Description |
|--------|-------------|
| `calculateProductPricing(Product, ?FlashSale)` | Full pricing calculation |
| `calculateVariantSalePrice(Product, variant, ?FlashSale)` | Variant price |
| `calculateProductCurrentPrice(Product)` | Final effective price |
| `isDiscountActive($product)` | Date + status check |
| `resolveActiveFlashSale(Product)` | Valid flash sale |

## Data Flow

```
Client
  |
  GET /api/v1/general/products?type=index&category=electronics&minPrice=10
  |
  v
General\ProductController@index(Request)
  |
  v
ProductService::paginate(Request)
  |--- buildFilteredBaseQuery() + applyFilters()
  |--- resolve strategy via ProductEngine
  |--- paginate() + enrichCollectionWithPricing()
  |
  v
ProductMiniResource collection
  |--- Maps: id, name, slug, price, current_price, ratings, image
  |
  v
JSON Response
```
