# Flash Sale Module — QA Test Cases (Public API)

## Test Files

No dedicated tests exist. One reference in `FastShippingControllerTest`.

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List flash sales | GET /general/flash-sales | 200, paginated |
| F2 | List with limit | ?limit=5 | Max 5 per page |
| F3 | List by IDs | ?flashSalesId=1,2 | Only specified |
| F4 | List with slug param | ?slug=x | Single flash sale |
| F5 | Get by slug | GET /general/flash-sales/summer | 200 with products |
| F6 | Get expired flash sale | Expired flash sale slug | 404 |
| F7 | Get inactive flash sale | status=false slug | 404 |
| F8 | Get not found | Non-existent slug | 404 |
| F9 | Flash sale products by qty | GET /general/flash-sale-products | Products from valid flash sales |
| F10 | Ending this week | GET /general/flash-sale-products-ending-this-week | Products ending within 7 days |
| F11 | Ending today | GET /general/flash-sale-products-ending-today | Products ending today |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | Invalid flashSalesId | ?flashSalesId=abc | Ignored, returns all |
| V2 | Future start_date | Flash sale starts next month | Excluded by valid() scope |
| V3 | Past end_date | Flash sale already ended | Excluded by valid() scope |

---

## Response Structure Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | List response structure | status, message, success, data | Correct keys |
| S2 | Flash sale object | id, name, discription (typo), slug, start_date, end_date, image | Correct types |
| S3 | Detail with products | products array included | Array of ProductMiniResource |
| S4 | Empty list | No valid flash sales | 200, empty array |
| S5 | Product structure | id, name, slug, price, current_price, ratings, image | Correct types |

---

## Channel Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| H1 | Default channel | No X-Channel header | Products filtered for home |

---

## Regression Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| R1 | Soft-deleted flash sale | Deleted flash sale in DB | Excluded from valid() |
| R2 | Flash sale with no products | Slug lookup with no associations | Products absent or empty |
| R3 | Flash sale with discount type | percentage/fixed_rate/final_price | Products show flash_sale_active=true |
