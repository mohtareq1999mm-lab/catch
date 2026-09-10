# Brand Module — QA Test Cases (Public API)

## Test Files

No existing tests for public brand endpoints.

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List brands | GET /general/brands | 200, array of brands |
| F2 | List brands with limit | GET /general/brands?limit=5 | 200, max 5 brands |
| F3 | List brands by IDs | GET /general/brands?brandsId=1,2,3 | Only brands 1,2,3 |
| F4 | List brands by date range | GET /general/brands?start_date=2026-01-01&end_date=2026-06-30 | Filtered by created_at |
| F5 | List brands with slug param | GET /general/brands?slug=nike | Single brand resource |
| F6 | Get brand by slug | GET /general/brands/nike | 200, brand with products |
| F7 | Get brand not found | GET /general/brands/nonexistent | 404 |
| F8 | Brands-products by qty | GET /general/brands-products?limit=2&limit_brand=3 | Products from 3 brands, 2 each |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Invalid brandId format | ?brandsId=abc | Ignored, returns all |
| V2 | Negative limit | ?limit=-1 | Treated as 0, empty |
| V3 | Zero limit_brand | ?limit_brand=0 | No brands, empty array |
| V4 | Very large limit | ?limit=10000 | Server max constraint |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | List response structure | status, message, success, data | Correct keys |
| S2 | Brand object structure | id, name, slug, image, status | Correct types |
| S3 | Image object | image.desktop, image.mobile | String or null |
| S4 | Brand detail products | products array in response | Array of product objects |
| S5 | Product object structure | id, name, slug, price, price_after_discount, rating, image | Correct types |
| S6 | Empty list | No active brands | 200, empty array |

---

## Channel Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| H1 | Default channel | No X-Channel header | All brands returned |
| H2 | Home channel | X-Channel: home | Brands filtered for home |
| H3 | Fast shipping channel | X-Channel: fast-shipping | Brands filtered for fast-shipping |

---

## Regression Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Soft-deleted brand | Soft-deleted brand in DB | Excluded from response |
| R2 | Inactive brand | status=0 brand | Excluded from response |
| R3 | Brand with no products | getBrandBySlug | Brand returned, products = empty |
| R4 | Brand with many products | 50+ products | Limited to default per_page |
| R5 | Product pricing enrichment | Product with active discount | price_after_discount reflects discount |
