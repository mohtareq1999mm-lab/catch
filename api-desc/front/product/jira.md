# Jira - Product Feature

## Epic: Product Management

### Story Points Estimate: 34

---

## User Stories

### US-001: Browse Products (Public)
**As** a customer
**I want** to browse products with search, filter, and sort capabilities
**So that** I can find products I want to purchase

**Acceptance Criteria:**
- `GET /api/v1/general/products` supports strategy-based listing (index, new arrivals, best sellers, flash sales, discounts)
- Supports filtering by category, brand, price range, attributes, dimensions, rating
- Supports full-text search via Meilisearch
- Supports sorting by price, newest, best selling
- Returns paginated results with product mini-resource (name, price, image, rating)

---

### US-002: View Product Details (Public)
**As** a customer
**I want** to view detailed product information
**So that** I can make an informed purchase decision

**Acceptance Criteria:**
- `GET /api/v1/general/products/{slug}` returns full product details
- Returns: description, images, price (with discount/flash sale), variants, categories, brands
- Returns customer reviews with ratings
- Returns related products
- Returns dynamic filters (brands, categories, attributes available for this product category)

---

### US-003: Admin CRUD - Create Product
**As** an admin user
**I want** to create products with complete details
**So that** I can add products to the catalog

**Acceptance Criteria:**
- `POST /api/v1/products` with translatable name and description
- Required: name (multi-language), description, categories, images, product_type, in_stock, has_discount, has_flash_sale
- Supports simple and variable product types
- Variable products support variants with different prices, stock, and attributes
- Auto-generates SKU in format `PRD-{id}`
- Supports discount and flash sale configuration
- Supports image upload (jpeg, png, jpg, max 2MB per image)

---

### US-004: Admin CRUD - Update/Delete Products
**As** an admin user
**I want** to update or remove products
**So that** I can keep the catalog current

**Acceptance Criteria:**
- `PUT /api/v1/products/{id}` partial update with full relation re-sync
- `DELETE /api/v1/products/{id}` soft deletes
- `POST /api/v1/products/bulk-delete` with array of IDs
- `DELETE /api/v1/products/all` deletes all products

---

### US-005: Discount & Flash Sale Pricing
**As** an admin user
**I want** to set discount and flash sale pricing on products
**So that** I can run promotional campaigns

**Acceptance Criteria:**
- Discounts: percentage or fixed amount with date range and optional manual override
- Flash sales: configurable via FlashSale entity
- Pricing hierarchy: Flash Sale > Discount > Base price
- `current_price` computed dynamically with `discount_active` and `flash_sale_active` flags
- Automatic price recalculation on product update

---

### US-006: Import/Export Products
**As** an admin user
**I want** to import products via CSV and export the product catalog
**So that** I can bulk-manage products

**Acceptance Criteria:**
- `POST /api/v1/import-products` accepts XLSX/XLS/ODS files
- Background job with progress tracking and cancellation support
- Creates products, variants, and attribute associations
- Downloads images from URLs
- `GET /api/v1/export-products/{shop_id}` exports to CSV
- Background export job with file download

---

### US-007: Product Reviews
**As** a customer
**I want** to add and manage product reviews
**So that** I can share my experience with other customers

**Acceptance Criteria:**
- `POST /api/v1/general/products/{id}/reviews` with rating, comment, and optional image
- `PUT /api/v1/general/products/reviews/{id}` to update own review
- Reviews show in product detail
- Review approval/rejection sends notification to vendor

---

### US-008: Rental Products
**As** a customer
**I want** to calculate rental pricing for products
**So that** I can understand the cost of renting

**Acceptance Criteria:**
- `GET /api/v1/products/calculate-rental-price` with product_id, dates, quantity, persons, locations
- Returns full price breakdown
- Checks availability via Spatie Period

---

### US-009: Fast Shipping & Channel Filtering
**As** a customer
**I want** to see products relevant to my browsing context
**So that** I get a tailored experience

**Acceptance Criteria:**
- Fast shipping channel filters to `is_fast_shipping_available = true`
- Home channel excludes fast-shipping products
- `PUT /api/v1/products/{id}/fast-shipping` toggle for admin

---

## Tasks

| Task ID | Description | Estimate (h) | Dependencies |
|---------|-------------|-------------|--------------|
| T-001 | Create products table migration | 3 | None |
| T-002 | Create Product model with relationships | 6 | T-001 |
| T-003 | Create ProductRepository | 4 | T-002 |
| T-004 | Create ProductController (Marvel) with CRUD | 8 | T-003 |
| T-005 | Create ProductController (General/Public) | 4 | T-002 |
| T-006 | Create FormRequests (create, update, bulk, import, export) | 6 | T-002 |
| T-007 | Create API Resources (Admin + Public + Mini) | 4 | T-002 |
| T-008 | Create ProductService (Public) | 8 | T-002 |
| T-009 | Create ProductFilter service | 6 | T-008 |
| T-010 | Create ProductPricingService | 6 | T-002 |
| T-011 | Create ProductEngine with strategy pattern | 8 | T-008 |
| T-012 | Create GraphQL schema, query, and mutator | 8 | T-002 |
| T-013 | Create Import/Export services + Jobs | 12 | T-003 |
| T-014 | Create permission enums | 1 | None |
| T-015 | Create product type/status enums | 1 | None |
| T-016 | Create events, listeners, notifications | 4 | T-002 |
| T-017 | Write translation keys | 2 | None |
| T-018 | Create ProductVariantFactory | 2 | T-001 |
| T-019 | Write tests (8+ test files) | 24 | T-001 to T-017 |

---

## Bug Tickets

| Ticket | Description | Priority | Severity |
|--------|-------------|----------|----------|
| BUG-001 | Empty migration files (no-op) | Low | Low |
| BUG-002 | Duplicate route definitions in Routes.php | Medium | Low |
| BUG-003 | No Product model factory (only ProductVariantFactory) | Low | Medium |
| BUG-004 | ProductReview events referenced but not found in codebase | High | Medium |
| BUG-005 | Inconsistent translatable search between Repository and Service | Low | Low |
