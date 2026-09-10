# Jira - Currency Feature

## Epic: Multi-Currency Support

### Story Points Estimate: 13

## User Stories

### US-001: Manage Currencies
**As** an admin
**I want** to create, view, update, and delete currencies
**So that** the store can support multiple currencies

**Acceptance Criteria:**
- Create currency with 3-letter ISO code, translatable name/symbol/country_name, decimal places, icon, active flag
- Duplicate/uppercase-variant codes rejected
- Soft delete currencies with no exchange rates
- Base currency and currencies with rates cannot be deleted (409 with distinct messages)

### US-002: Manage Exchange Rates
**As** an admin
**I want** to create, view, update, and delete exchange rates
**So that** conversion values stay current

**Acceptance Criteria:**
- One rate per currency per effective date (upsert on same day)
- Rate must be a positive numeric value
- Filter rates by currency, effective date, date range (`date_from`, `date_to`) and currency code
- Deleting a rate is a hard delete

### US-002b: Filter Currencies & Rates
**As** an admin
**I want** to search and filter the currencies and exchange-rate lists
**So that** I can find rows quickly in large datasets

**Acceptance Criteria:**
- `/currencies` supports `search` (code/numeric_code/name/symbol/country_name incl. translations), `code`, `is_active`, `sort_order`
- `/currency-rates` supports `date_from`, `date_to`, `code` in addition to `currency_id`/`effective_date`

### US-003: Set Base Currency
**As** an admin
**I want** to switch the base currency
**So that** prices and conversions reflect the primary currency

**Acceptance Criteria:**
- Base currency must be active
- Base currency must have a rate on/before today
- Switching updates settings and flushes price caches

### US-003b: Set Catalog Currency
**As** an admin
**I want** to switch the catalog currency independently
**So that** products display/store prices in a chosen catalog while orders/payments stay in the base currency

**Acceptance Criteria:**
- Catalog currency must be active and have a rate on/before today
- Switching updates **only** `catalog_currency_code` (base currency + `currency` option untouched)
- Requires `set-catalog-currency` permission
- Flushes price caches

### US-004: Convert Prices
**As** the system
**I want** bcmath-precision conversions with historical rate lookup
**So that** money values are accurate

**Acceptance Criteria:**
- Same-currency conversion is identity without DB queries
- Latest rate on/before a date is used; missing rate throws
- Conversions use scale-6 arithmetic and round to 2 decimals for prices

### US-005: Snapshot Currency on Orders
**As** the system
**I want** to record the currency snapshot on every order
**So that** orders are auditable even if rates change later

**Acceptance Criteria:**
- Orders store currency_code (= base), base_currency_code, catalog_currency_code, currency_rate, currency_rate_date, total_price (base amount), converted_total_price
- Snapshot refreshes when an order is updated after a base-currency change
- OrderResource exposes currency, base_currency, catalog_currency, exchange_rate, converted_total

### US-006: Public Currency List
**As** a storefront visitor
**I want** a fast, cached list of active currencies
**So that** the UI can render a currency selector

**Acceptance Criteria:**
- No authentication required
- Only active currencies, cached 4h under the `currencies` tag
- Cache invalidated on any currency/rate change

### US-007: Payment & Invoice Currency Sourcing

**As** the system
**I want** all payment artifacts to quote the order's base currency
**So that** invoices, QR codes, gateway invoices/refunds and reconciliations agree with the order

**Acceptance Criteria:**
- MyFatoorah invoice `DisplayCurrencyIso`, refunds, payment transactions, cashier-QR payload, invoice snapshots and reconciliation `compareCurrency` resolve to `base_currency_code ?? currency_code ?? config('payment.default_currency')`
- Legacy orders (no currency columns) still work via the config fallback

### US-008: Public Currency Selection

**As** a storefront visitor
**I want** to choose a display currency
**So that** I can view prices in my preferred currency

**Acceptance Criteria:**
- `POST /api/v1/general/currencies/select` accepts `currency_code` (must exist and be active)
- Authenticated users' selection is stored as a preference (`user_preferences`); guests send `X-Currency` header (no `guest_currency` cookie; frontend owns header)
- Returns `CURRENCY_SELECTED_SUCCESSFULLY` with the selected `CurrencyResource`
- The selection only takes effect when the admin setting `currency_selection_enabled` is `true`; otherwise the effective currency stays the catalog code (preference/header ignored)

### US-009: Currency Selection Toggle (Settings)

**As** an admin
**I want** to enable/disable customer currency selection
**So that** I control whether storefront visitors can switch display currency

**Acceptance Criteria:**
- `PUT /api/v1/settings` accepts `currency_selection_enabled` (boolean)
- It is merged into `options` without dropping other options and flushes the settings cache / effective-currency memo
- Both admin and public settings responses expose a top-level `currency_selection_enabled` boolean
- When `false` (default), effective currency always resolves to the catalog code

## Bug Tickets

| Ticket | Description | Priority | Severity |
|--------|-------------|----------|----------|
| BUG-001 | Non-numeric currency/rate IDs returned 500 instead of 404 | High | High |
| BUG-002 | Base currency without rates could be deleted (check order) | High | High |
| BUG-003 | Date comparisons failed on SQLite (raw `<=` vs `whereDate`) | Medium | Medium |
| BUG-004 | Rate string precision differs between SQLite and MySQL | Low | Low |
| BUG-005 | Reconciliation `compareCurrency` passed `null` (TypeError) for legacy orders | High | High |
| BUG-006 | `SET_CATALOG_CURRENCY_SUCCESSFULLY` constant/translations undefined | High | High |
| BUG-007 | `set-catalog` had no permission gate | High | High |
| BUG-008 | Reconciliation queue test asserted non-existent `low` queue | Low | Low |
