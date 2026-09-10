# Jira - Currency Feature (Frontend)

## Epic: Multi-Currency Admin & Storefront UI

### Story Points Estimate: 8

---

## User Stories

### FE-US-001: Currencies Data Table
**As** an admin
**I want** a paginated currencies table
**So that** I can browse, search, and manage store currencies

**Acceptance Criteria:**
- Fetches `GET /api/v1/currencies` with `limit`/`page`
- Filters/search: `search` (name/code/symbol/country), `code`, `is_active`, `sort_order`
- Columns: Code, Name, Symbol, Country, Decimals, Active, Base badge
- Actions: edit, delete, set base, set catalog
- Delete shows 409 error messages (`CANNOT_DELETE_BASE_CURRENCY` / `CANNOT_DELETE_CURRENCY_IN_USE`)
- Loading skeleton + error state

### FE-US-002: Currency Form Dialog (Create/Update)
**As** an admin
**I want** a create/edit currency dialog
**So that** I can add or update currency metadata

**Acceptance Criteria:**
- Uses `POST /api/v1/currencies` and `PUT /api/v1/currencies/{id}`
- Translatable fields `name`, `symbol`, `country_name` as `{en, ar}` objects
- `code` input uppercased (3 letters, validated `size:3`)
- `decimal_places` 0–4, `icon`, `is_active`, `sort_order`
- Server 422 validation errors mapped per field

### FE-US-003: Set Base Currency Dialog
**As** an admin
**I want** to switch the base currency
**So that** prices and conversions reflect the primary currency

**Acceptance Criteria:**
- Uses `POST /api/v1/currencies/{id}/set-base`
- Confirmation before switching
- 422 `CURRENCY_INACTIVE` / `EXCHANGE_RATE_NOT_FOUND` errors displayed
- Base badge updates after success

### FE-US-003b: Set Catalog Currency Dialog
**As** an admin
**I want** to switch the catalog currency independently
**So that** the display/catalog prices use a separate currency while orders stay in base

**Acceptance Criteria:**
- Uses `POST /api/v1/currencies/{id}/set-catalog`
- Confirmation before switching
- 422 `CURRENCY_INACTIVE` / `EXCHANGE_RATE_NOT_FOUND` errors displayed
- Catalog badge updates after success; base badge unchanged

### FE-US-004: Exchange Rates Table & Form
**As** an admin
**I want** a rates table with create/update/delete
**So that** conversion values stay current

**Acceptance Criteria:**
- Uses `GET /api/v1/currency-rates` with `currency_id`, `effective_date`, `date_from`, `date_to`, `code` filters
- Create/update via `POST` / `PUT /currency-rates/{id}` (upsert per currency/day)
- `exchange_rate` numeric > 0; `effective_date` date
- Delete is a hard delete with confirmation

### FE-US-005: Storefront Currency Selector

**As** a storefront visitor
**I want** a currency selector
**So that** I can view prices in my currency

**Acceptance Criteria:**
- Fetches cached `GET /api/v1/general/currencies` (active only, no auth)
- Renders code + symbol from the flat list
- Selecting a currency calls `POST /api/v1/general/currencies/select { currency_code }` to persist the choice (user preference / guest cookie)
- When the admin setting `currency_selection_enabled` is `false`, the selector is hidden/disabled (selection is ignored)
- Selecting a currency refreshes product prices client-side

---

## Frontend Tasks

| ID | Description | h | Component |
|----|-------------|---|-----------|
| FE-T-001 | Create CurrenciesTable with pagination + filters | 5 | `CurrenciesTable.vue` |
| FE-T-002 | Create CurrencyFormDialog (translatable fields) | 4 | `CurrencyFormDialog.vue` |
| FE-T-003 | Create SetBaseCurrencyDialog | 2 | `SetBaseCurrencyDialog.vue` |
| FE-T-003b | Create SetCatalogCurrencyDialog | 2 | `SetCatalogCurrencyDialog.vue` |
| FE-T-004 | Create ExchangeRatesTable with filters (currency/date range/code) | 5 | `ExchangeRatesTable.vue` |
| FE-T-005 | Create ExchangeRateFormDialog (upsert) | 3 | `ExchangeRateFormDialog.vue` |
| FE-T-006 | Create currency API service | 1 | `services/currencyApi.js` |
| FE-T-007 | Create storefront currency selector | 2 | `CurrencySelector.vue` |
| FE-T-008 | Map 409/422 error messages to UI | 2 | `components/errors.js` |

## API Routes

| Method | Endpoint | Permission | Usage |
|--------|----------|-----------|-------|
| GET | `/api/v1/currencies` | VIEW_CURRENCIES | Data table (+ search/code/is_active/sort_order) |
| POST | `/api/v1/currencies` | CREATE_CURRENCY | Create dialog |
| PUT | `/api/v1/currencies/{id}` | UPDATE_CURRENCY | Edit dialog |
| DELETE | `/api/v1/currencies/{id}` | DELETE_CURRENCY | Delete action |
| POST | `/api/v1/currencies/{id}/set-base` | SET_BASE_CURRENCY | Set base dialog |
| POST | `/api/v1/currencies/{id}/set-catalog` | SET_CATALOG_CURRENCY | Set catalog dialog |
| GET | `/api/v1/currency-rates` | VIEW_CURRENCY_RATES | Rates table (+ date_from/date_to/code) |
| POST | `/api/v1/currency-rates` | CREATE_CURRENCY_RATE | Rate form |
| PUT | `/api/v1/currency-rates/{id}` | UPDATE_CURRENCY_RATE | Rate form |
| DELETE | `/api/v1/currency-rates/{id}` | DELETE_CURRENCY_RATE | Rate delete |
| GET | `/api/v1/general/currencies` | Public | Storefront selector |
| POST | `/api/v1/general/currencies/select` | Public | Storefront selector (persist selection) |
