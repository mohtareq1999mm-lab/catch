# Data Flow - Currency Feature

## Flow 1: Create Currency (Admin)

```
Admin Client
  |
  POST /api/v1/currencies  { code: "EGP", name: {...}, symbol: {...}, ... }
  Authorization: Bearer <token>
  |
  v
auth:sanctum middleware
  |
  v
permission:create-currency middleware (Spatie)
  |
  v
CurrencyController@store(StoreCurrencyRequest)
  |
  +-- prepareForValidation(): code = strtoupper("egp") -> "EGP"
  +-- validate: size:3, regex ^[A-Za-z]{3}$, unique:currencies,code
  |     Fail? -> 422 field errors
  |
  v
CurrencyService::storeCurrency($validated)
  |
  +-- Currency::create($data)   [setCodeAttribute uppercases]
  +-- invalidatePriceCaches()
  |     flush tags: currencies, products, products_{type}...
  |
  v
CurrencyResource::make($currency)
  |
  v
{ status:200, message: 'Currency created successfully', success:true, data }
```

## Flow 2: Delete Currency with Guards (Admin)

```
Admin Client
  |
  DELETE /api/v1/currencies/5
  |
  v
auth:sanctum -> permission:delete-currency
  |
  v
CurrencyController@destroy(5)
  |
  +-- Currency::withTrashed()->find(5)      [not found -> 404]
  |
  v
CurrencyService::deleteCurrency($currency)
  |
  +-- code === getBaseCode()?
  |     YES -> CurrencyInUseException(REASON_BASE_CURRENCY)
  |            -> 409 CANNOT_DELETE_BASE_CURRENCY
  |
  +-- $currency->rates()->exists()?
  |     YES -> CurrencyInUseException(REASON_REFERENCED_BY_RATES)
  |            -> 409 CANNOT_DELETE_CURRENCY_IN_USE
  |
  +-- $currency->delete()   [soft delete -> deleted_at]
  +-- invalidatePriceCaches()
  |
  v
{ status:200, message: 'Currency deleted successfully', success:true }
```

## Flow 3: Set Base Currency (Admin)

```
Admin Client
  |
  POST /api/v1/currencies/2/set-base
  |
  v
auth:sanctum -> permission:set-base-currency
  |
  v
CurrencyController@setBase(2)
  |
  +-- Currency::withTrashed()->find(2)      [not found -> 404]
  |
  v
CurrencyService::setBaseCurrency($currency)
  |
  +-- DB::transaction
  |     +-- Settings::lockForUpdate()->first()
  |     +-- !$currency->is_active -> CurrencyInactiveException -> 422 CURRENCY_INACTIVE
  |     +-- CurrencyRate where currency_id=2 and whereDate(effective_date <= today) exists?
  |           NO -> CurrencyRateNotFoundException -> 422 EXCHANGE_RATE_NOT_FOUND
  |     +-- options['base_currency_code'] = code
  |     +-- options['currency'] = code
  |     +-- settings->save()
  |     +-- flushTag(FrontendResource::SETTINGS->value)
  |
  +-- memoize $this->baseCode / $this->baseCurrency
  +-- invalidatePriceCaches(flushSettings: true)
  |
  v
{ status:200, message: 'Base currency updated successfully', success:true, data }
```

## Flow 3a: Set Catalog Currency (Admin)

```
Admin Client
  |
  POST /api/v1/currencies/2/set-catalog
  |
  v
auth:sanctum -> permission:set-catalog-currency
  |
  v
CurrencyController@setCatalog(2)
  |
  +-- Currency::withTrashed()->find(2)      [not found -> 404]
  |
  v
CurrencyService::setCatalogCurrency($currency)
  |
  +-- DB::transaction
  |     +-- Settings::lockForUpdate()->first()
  |     +-- !$currency->is_active -> CurrencyInactiveException -> 422 CURRENCY_INACTIVE
  |     +-- CurrencyRate where currency_id=2 and whereDate(effective_date <= today) exists?
  |           NO -> CurrencyRateNotFoundException -> 422 EXCHANGE_RATE_NOT_FOUND
  |     +-- options['catalog_currency_code'] = code    [base_currency_code & currency untouched]
  |     +-- settings->save()
  |     +-- flushTag(FrontendResource::SETTINGS->value)
  |
  +-- memoize $this->catalogCode / $this->catalogCurrency
  +-- invalidatePriceCaches(flushSettings: true)
  |
  v
{ status:200, message: 'Catalog currency updated successfully', success:true, data }
```

## Flow 4: Convert Currency (internal engine)

```
Caller (Order snapshot / Product price / direct)
  |
  v
CurrencyService::convert(amount, fromCode, toCode, ?date)
  |
  v
CurrencyConversionService::convert()
  |
  +-- uppercase codes; date ?? today
  +-- from === to? -> identity result (rate '1', no DB query)
  |
  v
resolveRate(fromCode, date)      [CurrencyRate: whereDate(effective_date <= date) orderByDesc -> first]
  |                                none -> CurrencyRateNotFoundException
  v
resolveRate(toCode, date)
  |
  v
converted = bcdiv(bcmul(amount, targetRate, 6), sourceRate, 6)
rate      = bcdiv(targetRate, sourceRate, 6)
  |
  v
CurrencyConversionResult(amount, round(converted,2), rate, date, from, to, sourceRate, targetRate)
```

## Flow 5: Order Price Snapshot

```
OrderCreationService::createOrder / updateOrder
  |
  +-- totalPrice = round(finalTotal + shipping + fastFee, 2)
  +-- resolveCurrencySnapshot($totalPrice)
  |     +-- catalogCode = CurrencyService::getCatalogCode()
  |     +-- baseCode    = CurrencyService::getBaseCode()
  |     +-- conversion  = convert($totalPrice, catalogCode, baseCode)
  |     +-- [currency_code=baseCode, base_currency_code=baseCode,
  |          catalog_currency_code=catalogCode, currency_rate=rate,
  |          currency_rate_date=effectiveDate, total_price=convertedAmount,
  |          converted_total_price=convertedAmount]
  |
  +-- Schema::hasColumn('orders','currency_code') ? merge into create/update data
  |
  v
Order::create / update  ->  OrderResource exposes currency/base_currency/catalog_currency/exchange_rate/converted_total
```

## Flow 5a: Cart & Product Price Conversion (display layer)

```
CartResource / CartItemResource / ProductResource / ProductMiniResource
  |
  +-- catalogCode = CurrencyService::getCatalogCode()
  +-- baseCode    = CurrencyService::getBaseCode()
  +-- convertPrice(value, catalogCode, baseCode)   [null/empty -> null]
  |
  v
  Cart totals/items, product price/current_price, discount_amount (fixed only)
  = converted base-currency values; CartResource adds `currency` = base code
```

## Flow 6: Create Exchange Rate (upsert)

```
Admin Client
  |
  POST /api/v1/currency-rates  { currency_id:2, exchange_rate:"0.25", effective_date:"2026-08-10" }
  |
  v
auth:sanctum -> permission:create-exchange-rate
  |
  v
CurrencyRateController@store(StoreCurrencyRateRequest)
  |
  +-- validate: currency_id exists, exchange_rate numeric > 0, effective_date date
  |
  v
CurrencyRateService::store($validated)
  |
  +-- CurrencyRate where currency_id=2 AND whereDate(effective_date='2026-08-10') first?
  |     EXISTS -> update exchange_rate        (upsert)
  |     MISSING -> CurrencyRate::create        (insert)
  +-- invalidatePriceCaches()
  |
  v
{ status:200, message: 'Exchange rate created successfully', success:true, data+currency }
```

## Flow 7: List Exchange Rates with Filters (Admin)

```
Admin Client
  |
  GET /api/v1/currency-rates?currency_id=2&effective_date=&date_from=&date_to=&code=USD&limit=
  |
  v
auth:sanctum -> permission:view-exchange-rates
  |
  v
CurrencyRateController@index(Request)
  |
  +-- limit = clamp(default 15, 1..100)
  +-- read filters: currency_id, effective_date, date_from, date_to, code
  |
  v
CurrencyRateService::list(currencyId, effectiveDate, dateFrom, dateTo, code, limit)
  |
  +-- CurrencyRate::with('currency')
  |     +-- currency_id          -> where currency_id
  |     +-- effective_date       -> whereDate(effective_date, =)
  |     +-- date_from            -> whereDate(effective_date, >=)
  |     +-- date_to              -> whereDate(effective_date, <=)
  |     +-- code                 -> whereHas(currency, where code)
  |     +-- orderByDesc(effective_date, id) -> paginate
  |
  v
{ status:200, message, success:true, data: { data[], pagination meta } }
```

## Flow 8: List Currencies with Filters (Admin)

```
Admin Client
  |
  GET /api/v1/currencies?search=KW&code=&is_active=1&sort_order=0&limit=
  |
  v
auth:sanctum -> permission:view-currencies
  |
  v
CurrencyController@index(Request)
  |
  +-- limit = clamp(default 15, 1..100)
  +-- search   -> LIKE across code/numeric_code/name->en|ar/symbol->en|ar/country_name->en|ar
  +-- code     -> where code =
  +-- is_active-> filter_var(bool), null if absent
  +-- sort_order -> where sort_order =
  +-- orderBy(sort_order, code) -> paginate
  |
  v
{ status:200, message, success:true, data: { data[], pagination meta } }
```

## Flow 9: List Currencies (Public, cached)

```
Storefront
  |
  GET /api/v1/general/currencies      [no auth, throttle:public-api]
  |
  v
App\CurrencyController@index()
  |
  +-- Currency::active()->orderBy('sort_order')->orderBy('code')->get()
  +-- HasCache::remember('currencies', md5(fullUrl), CurrencyResource::collection(...), 4h)
  |     cache hit? -> return cached
  |     cache miss? -> store & return
  |
  v
{ status:200, message, success:true, data: [...] }
  |
  (Cache invalidated on any currency/rate write via invalidatePriceCaches)
```

## Flow 10: Select Currency (Public)

```
Storefront visitor
  |
  POST /api/v1/general/currencies/select   { currency_code: "KWD" }
  |                                          [no auth, throttle:public-api]
  v
SelectCurrencyRequest
  +-- validate: required, string, max:3,
  |             exists(currencies,code) where is_active = true
  |     Fail? -> 422
  |
  v
App\CurrencyController@select()
  |
  +-- code = strtoupper("kwd") -> "KWD"
  +-- user = auth('sanctum')->user() ?? auth()->user()
  +-- if (user)  UserCurrencyPreferenceService::setUserPreference(user, code)
  +-- // Guest: no cookie — frontend owns X-Currency header; backend reads via UserCurrencyPreferenceService::getHeaderCurrencyCode()
  +-- app(CurrencyService::class)->forgetEffectiveCode()
  |
  v
CurrencyResource::make(Currency where code = 'KWD')
  |
  v
{ status:200, message: 'Currency updated successfully', success:true, data }
  |
  (preference/cookie only honored when settings currency_selection_enabled = true)
```

## Flow 11: Effective Currency Resolution

```
Any display/checkout price call
  |
  v
CurrencyService::getEffectiveCode()
  |
  +-- memoized? -> return
  |
  +-- isCurrencySelectionEnabled() ?      [settings.options.currency_selection_enabled, default false]
  |     NO  -> effectiveCode = getCatalogCode()      [stored preference/header IGNORED]
  |     YES v
  |       user preference (validated active)? -> effectiveCode = preference
  |       X-Currency header (validated active)? -> effectiveCode = header
  |       otherwise                              -> effectiveCode = catalog code
  |
  v
effectiveCode -> getEffectiveCurrency() -> Currency lookup
```
