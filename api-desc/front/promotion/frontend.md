# Frontend - Promotion Feature

## Status

**No dedicated frontend Vue/React components** found in `resources/js/`. The frontend is a separate SPA.

## Consumption Patterns

### 1. Public Promotion Listing (Shop Page)

Promotions appear on the shop front as promotional banners/cards:

```
GET /api/v1/general/promotions

Response:
{
  "data": [
    {
      "id": 1,
      "name": "Summer Special 20% Off",
      "slug": "summer-special-20-off",
      "status": true,
      "image": {
        "desktop": "https://cdn.example.com/promotions/summer-desktop.jpg",
        "mobile": "https://cdn.example.com/promotions/summer-mobile.jpg"
      },
      "products": [
        { "id": 1, "name": "Product 1", "slug": "product-1", "status": true, "image": {} }
      ]
    }
  ]
}
```

### 2. Promotion Detail Page

```
GET /api/v1/general/promotions/{slug}

Response: { data: { id, name, slug, status, image, products } }
```

### 3. Checkout - Eligible Promotions (Cart Page)

When a user goes to checkout, eligible promotions for their cart are fetched:

```
GET /api/v1/general/checkout/promotions
Authorization: Bearer <token>

Response:
{
  "data": [
    {
      "id": 1,
      "name": "Summer Special 20% Off",
      "code": "SUMMER20",
      "type": "price",
      "type_amount": "percentage",
      "discount": 20,
      "value": 20,
      "max_discount_amount": 500,
      "minimum_order_amount": 1000,
      "is_eligible": true
    }
  ]
}
```

### 4. Checkout - Apply Promotion

When a user selects a promotion, the cart is updated:

```
POST /api/v1/checkout
Body: { ..., "selected_promotion_id": 1, "selected_gift_product_id": 5, ... }
Response: { ... totals: { subtotal, promotion_discount, final_total, gift_items } }
```

### 5. Product Listing - Filter by Promotion

Products can be filtered by promotion slug:

```
GET /api/v1/general/products?promotion=summer-special-20-off
```

## What a Frontend Implementation Would Need

### Public Components

```
PromotionCard.vue
  Props: promotion (object)
  Renders: promotion banner with image, name, product count

PromotionListingPage.vue
  Fetches: GET /api/v1/general/promotions
  Renders: grid of promotion cards

PromotionDetailPage.vue
  Fetches: GET /api/v1/general/promotions/{slug}
  Renders: promotion details + associated products

EligiblePromotionsPanel.vue (Checkout)
  Props: cart (object)
  Fetches: GET /api/v1/general/checkout/promotions
  Renders: list of eligible promotions with radio selection
  Events: onSelect(promotionId, giftProductId)

CheckoutTotals.vue
  Props: totals (CheckoutTotals)
  Renders: subtotal, promotion discount, coupon discount, final total, gift items list
```

### Admin Components

```
AdminPromotionListPage.vue
  Fetches: GET /api/v1/promotions (paginated)
  Filters: search, type, status, date range
  Table: name, type, discount, code, status, usage/limiter
  Actions: edit, delete

AdminPromotionForm.vue
  Sections:
    - Basic Info: name (multi-language), slug, code
    - Type: price or quantity
    - Discount Type: percentage, fixed_rate, or gift
    - Conditions: minimum_order_amount, required_quantity_type, apply_to (all/specific)
    - Product Selection: product_ids, gift_products (with variant and quantity)
    - Schedule: start_at, end_at
    - Limits: limiter (max uses)
    - Images: desktop + mobile upload
    - Status toggle
  Complex conditional logic:
    - If type_amount=gift → show gift product picker
    - If apply_to=specific_products → show product multi-select
    - If type_amount=percentage → show max_discount_amount
    - If type=quantity → show required_quantity_type

AdminPromotionCreatePage.vue
AdminPromotionEditPage.vue
```

## Key API Patterns

**Create with gift products:**
```
POST /api/v1/promotions
Content-Type: multipart/form-data
Body:
  name[en]: "Buy 2 Get 1 Free"
  type: "price"
  type_amount: "gift"
  apply_to: "all_products"
  gift_products[0][product_id]: 5
  gift_products[0][product_variant_id]: 12
  gift_products[0][quantity]: 1
  image-desktop: <file>
  image-mobile: <file>
```

**Create with specific products:**
```
POST /api/v1/promotions
Body:
  name[en]: "20% Off Electronics"
  type: "price"
  type_amount: "percentage"
  discount: 20
  max_discount_amount: 500
  minimum_order_amount: 1000
  apply_to: "specific_products"
  product_ids: [1, 2, 3]
  limiter: 100
  start_at: "2026-08-01"
  end_at: "2026-08-31"
```
