# Banner Module — QA Test Cases (Public API)

## Test Files

No dedicated tests exist for public banner endpoints.

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List banners | GET /general/banners | 200, array of banners |
| F2 | List with limit | GET /general/banners?limit=3 | Max 3 banners |
| F3 | List by IDs | GET /general/banners?bannersId=1,2 | Only banners 1,2 |
| F4 | List by date range | GET /general/banners?start_date=...&end_date=... | Filtered |
| F5 | List with slug param | GET /general/banners?slug=summer-sale | Single banner |
| F6 | Get by slug | GET /general/banners/summer-sale | 200 with products |
| F7 | Get by slug no products | GET /general/banners/summer-sale?with_products=false | 200 without products |
| F8 | Get by slug not found | GET /general/banners/nonexistent | 404 |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Invalid bannersId | ?bannersId=abc | Ignored, returns all |
| V2 | with_products=0 | String "0" | Products excluded |
| V3 | with_products=1 | String "1" | Products included |
| V4 | Negative limit | ?limit=-1 | Treated as 0, empty |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | List response structure | status, message, success, data | Correct keys |
| S2 | Banner object | id, title, slug, description, image, status | Correct types |
| S3 | Image object | image.desktop, image.mobile | String or null |
| S4 | Detail with products | products array in response | Array of ProductMiniResource |
| S5 | Detail without products | ?with_products=false | No products key |
| S6 | Empty list | No active banners | 200, empty array |
| S7 | Translated fields | Title in ar locale | Arabic text |

---

## Channel Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| H1 | Default channel | No X-Channel header | Banners returned |
| H2 | Home channel | X-Channel: home | Products filtered for home |
| H3 | Fast shipping | X-Channel: fast-shipping | Products filtered for fast-shipping |

---

## Regression Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Soft-deleted banner | Soft-deleted in DB | Excluded |
| R2 | Inactive banner | status=0 | Excluded |
| R3 | Banner with no products | Slug lookup | Products key absent or empty |
| R4 | Banner with many products | 50+ associated | All returned via relationship |
