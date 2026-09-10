# QA - Currency Feature

## Test Matrix

### Currencies (Admin)

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-CUR-001 | List returns paginated data array | data[] + links{} |
| TC-CUR-002 | List respects limit param | Correct count |
| TC-CUR-003 | Create currency with valid data | 200 + `CURRENCY_CREATED_SUCCESSFULLY` |
| TC-CUR-004 | Create rejects duplicate code (case-insensitive) | 422 |
| TC-CUR-005 | Create rejects invalid code length/format (must be 3 letters) | 422 |
| TC-CUR-006 | Create uppercases lowercase code | Stored as uppercase |
| TC-CUR-007 | Create rejects invalid decimal_places (< 0 or > 4) | 422 |
| TC-CUR-008 | Create stores translatable name/symbol/country_name | {en, ar} persisted |
| TC-CUR-009 | Update currency metadata | 200 + `CURRENCY_UPDATED_SUCCESSFULLY` |
| TC-CUR-010 | Show currency by numeric ID | 200 + data |
| TC-CUR-011 | Show returns 404 for non-numeric / missing ID | 404 |
| TC-CUR-012 | Delete currency with no rates | 200 soft delete (`deleted_at` set) |
| TC-CUR-013 | Delete base currency | 409 `CANNOT_DELETE_BASE_CURRENCY` |
| TC-CUR-014 | Delete currency referenced by rates | 409 `CANNOT_DELETE_CURRENCY_IN_USE` |
| TC-CUR-015 | Set base to active currency with rate ≤ today | 200 `SET_BASE_CURRENCY_SUCCESSFULLY` |
| TC-CUR-016 | Set base to inactive currency | 422 `CURRENCY_INACTIVE` |
| TC-CUR-017 | Set base to currency without a rate ≤ today | 422 `EXCHANGE_RATE_NOT_FOUND` |
| TC-CUR-018 | Unauthenticated | 401 (all endpoints) |
| TC-CUR-019 | Forbidden without permission | 403 |
| TC-CUR-020 | Set catalog to active currency with rate ≤ today | 200 `SET_CATALOG_CURRENCY_SUCCESSFULLY` |
| TC-CUR-021 | Set catalog updates only `catalog_currency_code` option | base_currency_code & currency untouched |
| TC-CUR-022 | Set catalog to inactive currency | 422 `CURRENCY_INACTIVE` |
| TC-CUR-023 | Set catalog to currency without a rate ≤ today | 422 `EXCHANGE_RATE_NOT_FOUND` |
| TC-CUR-024 | Set catalog missing currency | 404 |
| TC-CUR-025 | Set catalog requires `set-catalog-currency` permission | 403 |
| TC-CUR-026 | Set catalog flushes product/currency price caches | Cache cleared |
| TC-CUR-027 | List filters by `code` | Only matching currency |
| TC-CUR-028 | List filters by `search` (code/numeric_code/name/symbol/country_name) | Only matching currencies |
| TC-CUR-029 | List filters by `is_active` (0/1) | Only matching status |
| TC-CUR-030 | List filters by `sort_order` | Only matching sort order |

### Exchange Rates (Admin)

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-RATE-001 | List rates with pagination | data[] |
| TC-RATE-002 | List filters by `currency_id` | Only matching currency |
| TC-RATE-003 | List filters by `effective_date` | Only matching date |
| TC-RATE-004 | Create rate for currency/day | 200, row created |
| TC-RATE-005 | Post same rate for same currency/day (upsert) | Row updated, no duplicate |
| TC-RATE-006 | Create rate with exchange_rate ≤ 0 | 422 |
| TC-RATE-007 | Create rate with non-existent currency_id | 422 |
| TC-RATE-008 | Update rate `exchange_rate` | 200 `CURRENCY_RATE_UPDATED_SUCCESSFULLY` |
| TC-RATE-009 | Delete rate | Hard delete |
| TC-RATE-010 | Show/missing rate | 200 / 404 |
| TC-RATE-011 | List filters by `date_from` | Only rates with effective_date >= date |
| TC-RATE-012 | List filters by `date_to` | Only rates with effective_date <= date |
| TC-RATE-013 | List filters by `code` (currency ISO code) | Only matching currency rates |
| TC-RATE-014 | List `code` with unknown code | Empty result (total 0) |

### Conversion Logic

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-CONV-001 | Convert same currency → identity | Amount unchanged, no DB query |
| TC-CONV-002 | Convert using latest rate on/before date | Correct rate chosen |
| TC-CONV-003 | Convert with no historical rate | `CurrencyRateNotFoundException` |
| TC-CONV-004 | Conversion precision (bcmath scale 6, round 2) | `0.221` ratio, `22.10` total |
| TC-CONV-005 | Latest-wins when multiple rates for same date | Most recent `effective_date` used |

### Order Snapshot

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-ORD-001 | Order stores currency_code / base_currency_code | Persisted (currency_code == base_currency_code) |
| TC-ORD-002 | Order stores currency_rate (ratio) | e.g. `0.221000` |
| TC-ORD-003 | Order stores currency_rate_date | Effective date used |
| TC-ORD-004 | Order stores converted_total_price and total_price as base amount | e.g. `22.10` |
| TC-ORD-005 | OrderResource exposes currency/base_currency/catalog_currency/exchange_rate/converted_total | Present |
| TC-ORD-006 | Update order after base change refreshes snapshot | New snapshot values |

### Payment / Invoice / Reconciliation (currency sourcing)

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-PAY-001 | MyFatoorah invoice uses order base currency (DisplayCurrencyIso) | `base_currency_code` |
| TC-PAY-002 | MyFatoorah refund currency uses order base currency | `base_currency_code` |
| TC-PAY-003 | Payment transactions created with order base currency | `currency` = order base |
| TC-PAY-004 | Reconciliation `compareCurrency` uses order base currency | expected = order base |
| TC-PAY-005 | Invoice snapshot `pricing_breakdown.currency` uses order base | `base_currency_code` |
| TC-PAY-006 | Legacy orders (no currency columns) fall back to config default | `config('payment.default_currency')` |

### Public List

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-PUB-001 | Public list returns only active currencies | No inactive rows |
| TC-PUB-002 | Public list requires no auth | 200 |
| TC-PUB-003 | Public list cached under `currencies` tag | Cache hit on repeat call |
| TC-PUB-004 | Cache invalidated on currency/rate write | Fresh data on next call |

### Public Currency Select

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-SEL-001 | Select stores user preference (authenticated) | 200, preference persisted |
| TC-SEL-002 | Select sets guest cookie (unauthenticated) | 200, `guest_currency` cookie set |
| TC-SEL-003 | Select missing/inactive/invalid currency_code | 422 |
| TC-SEL-004 | Select returns `CURRENCY_SELECTED_SUCCESSFULLY` + CurrencyResource | 200 |
| TC-SEL-005 | Effective currency = catalog when `currency_selection_enabled` is false | Preference ignored |
| TC-SEL-006 | Effective currency = user preference when enabled | Preference used |
| TC-SEL-007 | Effective currency = guest cookie when enabled, no user | Cookie used |

### Currency Selection Setting

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-SET-001 | `PUT /api/v1/settings` accepts `currency_selection_enabled` boolean | 200 |
| TC-SET-002 | Setting merges into options without losing `fast_shipping` etc. | Other options preserved |
| TC-SET-003 | `SettingResource` exposes top-level `currency_selection_enabled` bool | Present (admin + public) |
| TC-SET-004 | Settings update resets effective-currency memo | Next resolution uses new value |

## Manual Test Checklist

- [ ] Verify delete guard order: base currency check happens before rates check
- [ ] Verify code is stored uppercase regardless of input casing
- [ ] Verify rate upsert: two posts same currency/day → one row, updated value
- [ ] Verify decimal precision: `0.2210000000` stored, `0.221000` ratio, `22.10` converted total
- [ ] Verify historical lookup picks rate `<=` the requested date (not the newest row globally)
- [ ] Verify set-base 422 messages shown when currency inactive or has no rate ≤ today
- [ ] Verify set-catalog 422 messages shown when currency inactive or has no rate ≤ today
- [ ] Verify set-catalog does not change base currency or the `currency` option
- [ ] Verify storefront list only shows active currencies, ordered by sort_order then code
- [ ] Verify price caches flushed when base/catalog currency changes or a rate is written
- [ ] Verify 409 delete errors return distinct codes/messages for base vs in-use currency
- [ ] Verify `/currencies` filters: search (translated fields), code, is_active, sort_order
- [ ] Verify `/currency-rates` filters: date_from, date_to, code
- [ ] Verify MyFatoorah invoice `DisplayCurrencyIso` matches the order's base currency
- [ ] Verify `POST /api/v1/general/currencies/select` persists user preference / guest cookie and returns `CURRENCY_SELECTED_SUCCESSFULLY`
- [ ] Verify `select` returns 422 for missing/inactive currency_code
- [ ] Verify effective currency is the catalog code when `currency_selection_enabled` is false, even with a stored preference
- [ ] Verify `PUT /api/v1/settings` with `currency_selection_enabled` merges into options (does not drop `fast_shipping`/other keys)
- [ ] Verify both admin and public settings responses include the top-level `currency_selection_enabled` bool
