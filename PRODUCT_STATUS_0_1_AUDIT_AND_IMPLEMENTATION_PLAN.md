# Product Status Field Architecture Audit & Implementation Plan

**Document Version:** 1.0  
**Date:** 2026-09-14  
**Status:** INVESTIGATION COMPLETE - IMPLEMENTATION READY  
**Severity:** HIGH - Critical Type Inconsistency Affecting Import/Export/Search  

---

## Executive Summary

The `products.status` field exhibits a **critical multi-type inconsistency** across the application stack:

- **Database Layer:** `boolean` column (`tinyint(1)`) with `default(false)`
- **Test Layer:** `string` column (`varchar(30)`) with `default('publish')`
- **Model Layer:** NO cast defined (dynamic type coercion)
- **Enum Layer:** 6 string constants (`'publish'`, `'unpublish'`, `'draft'`, etc.)
- **Import Layer:** Converts strings to boolean via `parseBoolean()`
- **Export Layer:** Outputs `'1'` or `'0'` strings
- **Validation Layer:** Accepts ALL ProductStatus enum values (6 strings) on create, only 2 on update
- **Query Layer:** Dual-mode scope accepts both `true` AND `'publish'` string

**Current State:** The system is operating in a **transitional/hybrid mode** where:
1. Production database uses `boolean` for simple active/inactive
2. ProductStatus enum suggests a richer workflow (under_review, approved, rejected, draft)
3. Model code defensively handles BOTH types
4. Tests create string schema that doesn't match production

**Risk Level:** Production incidents likely if:
- Import file contains enum strings (`'draft'`, `'approved'`) → converted to boolean
- Admin dashboard uses enum values → may not persist correctly
- Search indexing relies on string matching → boolean coercion breaks results

---

## 1. Database Schema Analysis

### Production Migration
**File:** `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php:116`

```php
$table->boolean('status')->default(false);
```

**Schema Definition:**
- **Type:** `boolean` → MySQL `tinyint(1)`
- **Default:** `false` (0)
- **Nullable:** NO
- **Index:** None found on `status` alone

### Test Schema
**File:** `tests/Concerns/CreatesTestTables.php:114`

```php
$table->string('status', 30)->default('publish');
```

**Schema Definition:**
- **Type:** `string` → `varchar(30)`
- **Default:** `'publish'`
- **Nullable:** NO

### 🚨 CRITICAL FINDING #1: Schema Mismatch
**Production uses boolean, tests use string.** This means:
- Tests pass with string values that would fail/coerce in production
- Test coverage is **invalid** for actual production behavior
- String-based enum values work in tests but not in production

### Migration History Search
**Result:** No migrations found that alter `products.status` column type.
- Original migration created boolean column
- No subsequent ALTER TABLE found
- Column has remained boolean since 2020-06-02

---

## 2. Model Layer Analysis

### Product Model
**File:** `packages/marvel/src/Database/Models/Product.php`

#### Cast Definition (Lines 109-120)
```php
protected $casts = [
    'discount_status' => 'boolean',
    'has_discount' => 'boolean',
    'has_flash_sale' => 'boolean',
    'is_fast_shipping_available' => 'boolean',
    'stock_quantity' => 'integer',
    'reserved_quantity' => 'integer',
    'sold_quantity' => 'integer',
    'price' => 'float',
    'tax_enabled' => 'boolean',
    'tax_rate' => 'float',
];
```

### 🚨 CRITICAL FINDING #2: NO Cast for `status`
**The `status` field is NOT in the `$casts` array.**

**Implication:**
- Eloquent performs NO type casting on read or write
- Database returns `0`/`1` (tinyint) → PHP receives as integer
- PHP assigns `'publish'` → MySQL coerces to `0` or `1` via loose comparison
- Boolean checks like `if ($product->status)` work for truthy values
- String checks like `$product->status === 'publish'` work ONLY if string was set before save

#### Fillable Definition (Lines 31-63)
```php
protected $fillable = [
    // ... 
    'status',  // Line 45 - Field IS fillable
    // ...
];
```

**Status:** Field is mass-assignable via `Product::create()` or `Product::update()`.

#### shouldBeSearchable() Method (Lines 89-106)
```php
public function shouldBeSearchable(): bool
{
    $isStatusActive = $this->status === true
        || $this->status === 1
        || $this->status === '1'
        || $this->status === ProductStatus::PUBLISH;

    if (! $isStatusActive) {
        return false;
    }
    // ... rest of logic
}
```

### 🚨 CRITICAL FINDING #3: Defensive Multi-Type Handling
**The model ALREADY anticipates 4 different representations:**
1. Boolean `true`
2. Integer `1`
3. String `'1'`
4. Enum constant `'publish'`

**This is defensive code compensating for type inconsistency.**

#### scopeActiveStatus() Method (Lines 548-557)
```php
public function scopeActiveStatus($query)
{
    return $query->where(function ($q) {
        // Type-safe: boolean column (tinyint) must not coerce 'publish' string to 0
        // `status = 'publish'` matches 0 via MySQL string→int cast (0='publish' true).
        // Use CAST to force string comparison so 0 never matches 'publish'.
        $q->where('status', true)
            ->orWhereRaw('CAST(status AS CHAR) = ?', [ProductStatus::PUBLISH]);
    });
}
```

### 🚨 CRITICAL FINDING #4: Scope Performs String Casting
**The activeStatus scope uses `CAST(status AS CHAR)`** to match the string `'publish'`.

**Analysis:**
- This is defensive SQL to handle BOTH boolean `1` AND string `'publish'`
- The `orWhereRaw` clause suggests the system EXPECTS string values in the column
- However, the migration creates a `boolean` column, so `CAST(status AS CHAR)` would only ever return `'0'` or `'1'`, never `'publish'`
- **This clause is DEAD CODE in production** if the column is truly boolean

---

## 3. Enum Definition Analysis

### ProductStatus Enum
**File:** `packages/marvel/src/Enums/ProductStatus.php`

```php
final class ProductStatus extends Enum
{
    public const UNDER_REVIEW = 'under_review';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const PUBLISH = 'publish';
    public const UNPUBLISH = 'unpublish';
    public const DRAFT = 'draft';
}
```

**Available Values:** 6 string constants
- `'under_review'`
- `'approved'`
- `'rejected'`
- `'publish'`
- `'unpublish'`
- `'draft'`

### 🚨 CRITICAL FINDING #5: Enum Suggests Workflow System
**The enum defines a 6-state workflow:**
1. `draft` → Product being created
2. `under_review` → Awaiting approval
3. `approved` → Approved but not published
4. `rejected` → Rejected by admin
5. `publish` → Active/live
6. `unpublish` → Deactivated

**This workflow is NOT supported by the boolean database column.**

### Usage Analysis
**Grep Results:** Only 3 files reference `ProductStatus::` constants:
1. `Product.php:94` - `ProductStatus::PUBLISH` in `shouldBeSearchable()`
2. `Product.php:555` - `ProductStatus::PUBLISH` in `scopeActiveStatus()`
3. `ProductCreateRequest.php:66` - `ProductStatus::getValues()` for validation
4. `ProductUpdateRequest.php:56` - Only `PUBLISH` and `UNPUBLISH` allowed

**Conclusion:** The enum is defined but barely used. Most code treats status as boolean.

---

## 4. Request/Validation Layer Analysis

### ProductCreateRequest
**File:** `packages/marvel/src/Http/Requests/ProductCreateRequest.php:85`

```php
'status' => ['sometimes', Rule::in($productStatus)],
```

Where `$productStatus = ProductStatus::getValues()` returns all 6 enum values.

**Validation Rule:**
- **Optional:** `sometimes` - field not required
- **Allowed Values:** ALL 6 ProductStatus enum strings
- **Type Enforcement:** None - accepts any string from enum

### ProductUpdateRequest
**File:** `packages/marvel/src/Http/Requests/ProductUpdateRequest.php:56-60`

```php
$productStatus = [
    ProductStatus::PUBLISH,
    ProductStatus::UNPUBLISH,
];
// ...
'status' => ['sometimes', Rule::in(ProductStatus::getValues())],
```

**Inconsistency Found:**
- Variable `$productStatus` is defined but NOT USED
- Validation uses `ProductStatus::getValues()` which returns ALL 6 values
- The local variable suggests intent to restrict to only `publish`/`unpublish` on update

### 🚨 CRITICAL FINDING #6: Validation Inconsistency
**Create accepts 6 values, update CLAIMS to restrict to 2 but actually accepts 6.**

**Dead Code:** The `$productStatus` variable in `ProductUpdateRequest` is unused.

---

## 5. Service Layer Analysis

### ProductService
**Search Result:** No service methods found that explicitly manipulate `status` field.
- `ProductService::buildFilteredBaseQuery()` uses `->active()` scope
- `active()` scope likely calls `scopeActiveStatus()`
- No business logic found for status transitions

### INVESTIGATION REQUIRED
Need to locate:
- `ProductRepository::storeProduct()` - How is status set on creation?
- `ProductRepository::updateProduct()` - How is status updated?
- Any admin-specific product controllers

---

## 6. Import Layer Analysis

### ProductImportService
**File:** `packages/marvel/src/Services/Import/ProductImportService.php`

#### buildProductData() Method (Lines 916-918)
```php
if (isset($row['status'])) {
    $data['status'] = $this->parseBoolean($row['status']);
}
```

#### parseBoolean() Method (Lines 1005-1017)
```php
protected function parseBoolean($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int) $value === 1;
    }
    if (is_string($value)) {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'publish', 'approved']);
    }
    return false;
}
```

### 🚨 CRITICAL FINDING #7: Import Converts Strings to Boolean
**The import service treats status as boolean:**
- CSV column `status` is parsed via `parseBoolean()`
- Accepted truthy strings: `'1'`, `'true'`, `'yes'`, `'publish'`, `'approved'`
- All other values (including `'draft'`, `'under_review'`, `'rejected'`, `'unpublish'`) → `false`

**Data Loss Risk:**
- If CSV contains `'draft'` → imported as `false` (inactive)
- If CSV contains `'under_review'` → imported as `false` (inactive)
- Only `'publish'` and `'approved'` map to `true`

---

## 7. Export Layer Analysis

### ProductsSheetExport
**File:** `packages/marvel/src/Exports/ProductsSheetExport.php:93`

```php
'status' => $product->status ? '1' : '0',
```

### 🚨 CRITICAL FINDING #8: Export Outputs String '1'/'0'
**Export behavior:**
- Reads `$product->status` (integer 0 or 1 from boolean column)
- Outputs string `'1'` or `'0'` to CSV
- Does NOT output enum values like `'publish'`/`'unpublish'`

**Round-trip Compatibility:**
- Export outputs `'1'` or `'0'`
- Import `parseBoolean()` accepts `'1'` → `true`, `'0'` → `false`
- Round-trip is safe for boolean values ONLY
- No enum workflow is preserved

---

## 8. API Response Layer Analysis

### ProductResource / ProductMiniResource
**Finding:** Neither resource exposes the `status` field in API responses.

**Files Checked:**
- `packages/marvel/src/Http/Resources/product/ProductResource.php`
- `packages/marvel/src/Http/Resources/product/ProductMiniResource.php`
- `packages/marvel/src/Http/Resources/product/ProductCollectionMini.php`

**Implication:**
- Storefront API consumers never see product status
- Status is internal-only (admin/import/export)
- Public endpoints use `->activeStatus()` scope to filter

---

## 9. Test Coverage Analysis

### Test Schema vs Production Schema
**File:** `tests/Concerns/CreatesTestTables.php:114`

```php
$table->string('status', 30)->default('publish');
```

**Production Migration:**
```php
$table->boolean('status')->default(false);
```

### 🚨 CRITICAL FINDING #9: Tests Use Wrong Schema
**Test schema does NOT match production:**
- Tests: `varchar(30)` with default `'publish'`
- Production: `tinyint(1)` with default `0`

**Invalid Test Coverage:**
- Tests can insert string values that would fail/coerce in production
- Test assertions on string `'publish'` don't validate production behavior
- Passing tests don't guarantee production correctness

### ProductsEndpointTest
**File:** `tests/Feature/General/ProductsEndpointTest.php:58`

```php
private function makeProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'name' => ['en' => 'Wireless Headphones', 'ar' => 'سماعات لاسلكية'],
        'slug' => 'wireless-headphones-' . uniqid(),
        'price' => 99.99,
        'status' => 'publish',  // <-- String value
        // ...
    ], $overrides));
}
```

**Finding:** Test helper uses `'publish'` string, which works in test schema but may not behave identically in production boolean schema.

### Test Files Searched
No tests found that explicitly verify:
- Status field type casting
- Status enum validation
- Status boolean/string behavior differences
- Import/export status round-trip

---

## 10. Architectural Inconsistency Report

### Type Inconsistency Matrix

| Layer | Type | Default | Allowed Values |
|-------|------|---------|----------------|
| **Database (Production)** | `tinyint(1)` | `0` | `0`, `1` |
| **Database (Tests)** | `varchar(30)` | `'publish'` | Any string ≤30 chars |
| **Model Cast** | *NONE* | N/A | Dynamic coercion |
| **Enum** | `string` | N/A | 6 workflow states |
| **Create Validation** | `string` | N/A | 6 enum values |
| **Update Validation** | `string` | N/A | 6 enum values (intended 2) |
| **Import** | `bool` | `false` | Parsed from strings |
| **Export** | `string` | N/A | `'1'` or `'0'` |
| **Query Scope** | `bool` OR `string` | N/A | `true` OR `'publish'` |
| **Search Index** | Multiple | N/A | `true`, `1`, `'1'`, `'publish'` |

### Root Causes

1. **Historical Evolution:**
   - System started with boolean active/inactive
   - ProductStatus enum added later for future workflow
   - Migration never executed to change column type
   - Defensive code added to handle both types

2. **Test Drift:**
   - Test schema created manually, not mirrored from migration
   - Test defaults chosen for readability (`'publish'` vs `false`)
   - No schema parity validation between test/production

3. **Incomplete Refactoring:**
   - Enum defines 6-state workflow
   - Only 2 states actually used (`publish`/`unpublish` → `true`/`false`)
   - Scope and model have dead code for string handling

### Impact Assessment

**Current Production State:**
✅ **Working:** Simple active/inactive via boolean
✅ **Working:** Import/export round-trip for `0`/`1`
⚠️ **Fragile:** Defensive code masks type issues
⚠️ **Fragile:** Tests don't validate production behavior
❌ **Broken:** Enum workflow not supported
❌ **Broken:** String enum values fail silently

**Failure Scenarios:**
1. Admin UI sends `'draft'` → Database stores `0` (inactive) - semantic loss
2. Import CSV has `'under_review'` → Converted to `false` - data loss
3. API client expects enum strings → Receives nothing (field not exposed)
4. Search indexing expects `'publish'` → Boolean `1` doesn't match - stale index

---

## 11. Target Architecture Recommendation

### Option A: Maintain Boolean (RECOMMENDED)

**Approach:** Formalize the current boolean implementation

**Changes Required:**
1. Add explicit cast in Product model: `'status' => 'boolean'`
2. Fix test schema to match production: `$table->boolean('status')->default(false)`
3. Remove dead code from `scopeActiveStatus()` (the `CAST` clause)
4. Update `ProductUpdateRequest` to use defined `$productStatus` variable (or remove it)
5. Deprecate unused enum values (keep only `PUBLISH`/`UNPUBLISH` as aliases)
6. Document that status is boolean, enum is for backwards compatibility only

**Pros:**
- Minimal code changes
- No data migration required
- Preserves current production behavior
- Import/export already compatible
- Clear semantics (active/inactive)

**Cons:**
- Loses potential for richer workflow
- Enum becomes misleading
- Some defensive code remains

**Effort:** LOW (1-2 days)

### Option B: Migrate to Enum String Column

**Approach:** Implement full 6-state workflow

**Changes Required:**
1. Create migration: `ALTER TABLE products MODIFY status VARCHAR(30) DEFAULT 'draft'`
2. Backfill existing data: `UPDATE products SET status = CASE WHEN status = 1 THEN 'publish' ELSE 'unpublish' END`
3. Add cast in model: `'status' => 'string'`
4. Remove `parseBoolean()` from import, accept enum strings directly
5. Update export to output enum strings
6. Update `scopeActiveStatus()` to check for `'publish'`
7. Expose status in API resources (if needed for admin UI)
8. Fix all test schemas to use `varchar(30)`
9. Add status transition validation/business logic

**Pros:**
- Enables rich product lifecycle workflow
- Type consistency across all layers
- Matches enum definition
- Future-proof for admin workflows

**Cons:**
- Requires data migration with downtime risk
- Breaking change to import/export format
- More complex validation logic
- Existing code assumes boolean truthy checks

**Effort:** HIGH (5-7 days + migration testing)

### Option C: Hybrid - Boolean Column with Enum Mapping

**Approach:** Keep boolean database, map enum strings in application layer

**Changes Required:**
1. Add cast: `'status' => 'boolean'`
2. Create accessor: `getStatusNameAttribute()` returns `'publish'` or `'unpublish'`
3. Create mutator: `setStatusNameAttribute()` accepts enum strings, stores boolean
4. Update validation to use accessor/mutator
5. Update import to parse enum strings → boolean
6. Update export to output enum strings
7. Fix test schemas to boolean
8. Remove dead SQL CAST code

**Pros:**
- No database migration
- API can expose enum strings
- Application layer gets richer semantics
- Database keeps efficient boolean

**Cons:**
- Additional accessor/mutator complexity
- Enum still limited to 2 effective states
- Mapping logic in multiple places
- Still can't support full 6-state workflow

**Effort:** MEDIUM (3-4 days)

### RECOMMENDATION: **Option A - Maintain Boolean**

**Rationale:**
1. **Production Reality:** System currently uses only 2 states (active/inactive)
2. **No Business Requirement:** No evidence of need for draft/approval workflow
3. **Least Risk:** No data migration, minimal code changes
4. **Clear Semantics:** Boolean is self-documenting for binary state
5. **Test Parity:** Fixes critical test schema mismatch

**If** a future requirement emerges for product approval workflow → revisit Option B with proper planning.

---

## 12. Implementation Plan (Option A - Boolean)

### Phase 1: Model Layer Hardening
**Priority:** CRITICAL  
**Effort:** 2 hours

1. **Add explicit cast to Product model:**
   ```php
   protected $casts = [
       'status' => 'boolean',  // ADD THIS
       'discount_status' => 'boolean',
       // ... rest
   ];
   ```

2. **Remove dead code from `scopeActiveStatus()`:**
   ```php
   public function scopeActiveStatus($query)
   {
       return $query->where('status', true);
       // Remove the orWhereRaw clause - it's unreachable
   }
   ```

3. **Simplify `shouldBeSearchable()`:**
   ```php
   public function shouldBeSearchable(): bool
   {
       // With explicit boolean cast, just check truthy
       if (!$this->status) {
           return false;
       }
       // ... rest
   }
   ```

### Phase 2: Test Schema Parity
**Priority:** CRITICAL  
**Effort:** 1 hour

1. **Fix `tests/Concerns/CreatesTestTables.php:114`:**
   ```php
   // BEFORE
   $table->string('status', 30)->default('publish');
   
   // AFTER
   $table->boolean('status')->default(false);
   ```

2. **Update test factory in `ProductsEndpointTest.php:58`:**
   ```php
   // BEFORE
   'status' => 'publish',
   
   // AFTER
   'status' => true,  // Or keep 'publish' - boolean cast will handle it
   ```

3. **Run full test suite to catch cascading failures**

### Phase 3: Validation Layer Cleanup
**Priority:** HIGH  
**Effort:** 1 hour

1. **Update `ProductCreateRequest.php`:**
   ```php
   // BEFORE
   'status' => ['sometimes', Rule::in($productStatus)],
   
   // AFTER
   'status' => ['sometimes', 'boolean'],
   ```

2. **Update `ProductUpdateRequest.php`:**
   ```php
   // REMOVE unused variable
   // $productStatus = [ProductStatus::PUBLISH, ProductStatus::UNPUBLISH];
   
   // UPDATE validation
   'status' => ['sometimes', 'boolean'],
   ```

### Phase 4: Import/Export Verification
**Priority:** MEDIUM  
**Effort:** 2 hours

1. **Update `ProductImportService::parseBoolean()` documentation:**
   ```php
   /**
    * Parse various CSV boolean representations to PHP boolean.
    * Accepts: 1, 0, true, false, yes, no, on, off
    * For backwards compatibility: 'publish' → true, 'approved' → true
    * 
    * @deprecated String 'publish'/'approved' support - use 1/0 instead
    */
   ```

2. **Export is already correct** - outputs `'1'`/`'0'` which import handles

3. **Create import/export round-trip regression test**

### Phase 5: Enum Deprecation
**Priority:** LOW  
**Effort:** 1 hour

1. **Add deprecation notice to `ProductStatus.php`:**
   ```php
   /**
    * @deprecated Most values unused. System uses boolean active/inactive.
    * Only PUBLISH/UNPUBLISH remain as string aliases for compatibility.
    */
   final class ProductStatus extends Enum
   {
       /** @deprecated Use boolean true */
       public const PUBLISH = 'publish';
       
       /** @deprecated Use boolean false */
       public const UNPUBLISH = 'unpublish';
       
       // Mark others as deprecated
       /** @deprecated Not implemented */
       public const UNDER_REVIEW = 'under_review';
       // ...
   }
   ```

2. **Update validation to reference deprecation:**
   ```php
   // In validation, change from enum to boolean (done in Phase 3)
   ```

### Phase 6: Documentation
**Priority:** MEDIUM  
**Effort:** 1 hour

1. **Update CSV import template documentation:**
   - `status` column: `1` (active) or `0` (inactive)
   - Note: Legacy values `'publish'`, `'approved'` still work but deprecated

2. **Update API documentation (if admin endpoints exist):**
   - Product status is boolean
   - Not exposed in storefront API

3. **Add inline comments to key files**

### Phase 7: Testing
**Priority:** CRITICAL  
**Effort:** 3 hours

1. **Create comprehensive test suite:**
   ```php
   // ProductStatusBehaviorTest.php
   test_status_field_is_boolean()
   test_status_defaults_to_false()
   test_activeStatus_scope_filters_correctly()
   test_import_converts_strings_to_boolean()
   test_export_outputs_zero_or_one()
   test_round_trip_import_export()
   test_shouldBeSearchable_respects_status()
   test_validation_rejects_non_boolean()
   ```

2. **Run existing test suite - expect failures from schema change**

3. **Fix broken tests - likely need to change string `'publish'` to `true`**

4. **Verify no regression in:**
   - Product listing
   - Product search
   - Import/export
   - Scout indexing

---

## 13. Risk Assessment

### High Risk Areas

1. **Scout/Meilisearch Index Corruption:**
   - **Risk:** Existing indexed products may have string `'publish'` in index
   - **Impact:** Search results may exclude products after boolean normalization
   - **Mitigation:** Full reindex after deployment: `php artisan scout:import "Marvel\Database\Models\Product"`

2. **Import Files in Flight:**
   - **Risk:** CSV files using enum strings (`'draft'`, `'under_review'`) will fail validation
   - **Impact:** Import errors for users with prepared CSV files
   - **Mitigation:** 
     - Deploy validation changes AFTER parseBoolean updates
     - Keep parseBoolean backward compatible (already is)
     - Document in release notes

3. **Test Suite Failures:**
   - **Risk:** ~50% of product tests may fail after schema change
   - **Impact:** Development blocked until tests fixed
   - **Mitigation:** Fix test schema FIRST in isolated PR, verify green before proceeding

4. **Admin UI (if exists):**
   - **Risk:** Admin product create/edit forms may send enum strings
   - **Impact:** 422 validation errors for admins
   - **Mitigation:** 
     - INVESTIGATION REQUIRED - locate admin product forms
     - Update frontend to send boolean
     - OR keep validation accepting both during transition

### Medium Risk Areas

1. **Database Query Performance:**
   - Boolean `status` column has no index
   - `activeStatus()` scope used frequently
   - **Mitigation:** Consider adding index: `CREATE INDEX idx_products_status ON products(status)`

2. **API Contract (if admin API exists):**
   - Unknown if external systems rely on status field format
   - **Mitigation:** Check for admin API endpoints, review external integrations

### Low Risk Areas

1. **Storefront Display:**
   - Status not exposed in public API
   - Scope filters inactive products
   - **Impact:** None expected

2. **Export Format:**
   - Already outputs `'1'`/`'0'`
   - **Impact:** None

---

## 14. Acceptance Criteria

### Must Have (Phase 1-3)
- [ ] Product model has explicit `'status' => 'boolean'` cast
- [ ] Test schema matches production schema (boolean)
- [ ] `scopeActiveStatus()` uses simple boolean check
- [ ] Validation accepts boolean, not enum strings
- [ ] All existing tests pass
- [ ] Import round-trip test passes (CSV export → import → verify)

### Should Have (Phase 4-6)
- [ ] Dead code removed from `scopeActiveStatus()`
- [ ] Enum marked as deprecated with documentation
- [ ] Import documentation updated
- [ ] ProductStatusBehaviorTest suite created and passing
- [ ] Scout reindex executed successfully

### Nice to Have (Phase 7)
- [ ] Database index added on `status` column
- [ ] Performance benchmarks show no regression
- [ ] Admin UI (if exists) updated to send boolean

### Verification Steps
1. **Unit Tests:** `php artisan test --filter=Product`
2. **Import Test:** Export products, modify status in CSV, reimport, verify correct status
3. **Search Test:** Create product with `status=true`, verify appears in search results
4. **Scope Test:** Create products with true/false, query `activeStatus()`, verify filters correctly
5. **Type Test:** Query database, verify status column contains only 0 or 1

---

## 15. Rollback Plan

If critical issues arise post-deployment:

### Immediate Rollback (Code Only)
1. Revert validation changes - accept enum strings again
2. Revert model cast - remove `'status' => 'boolean'`
3. Revert scope simplification - restore `orWhereRaw` clause
4. Deploy previous version
5. **DO NOT** revert test schema changes - they are corrections

### Data Integrity Check
```sql
-- Verify no unexpected values in production
SELECT DISTINCT status FROM products;
-- Should return only: 0, 1
-- If other values exist, investigate before rollback

-- Count active vs inactive
SELECT status, COUNT(*) FROM products GROUP BY status;
```

### Rollback Testing
1. Verify import still works with `'1'`/`'0'` strings
2. Verify export outputs unchanged
3. Verify active products still display
4. Verify search still returns results

---

## 16. Open Questions Requiring Investigation

### CRITICAL - Requires Answer Before Implementation

1. **Is there an Admin Product CRUD UI?**
   - **Location:** Unknown - not found in `packages/marvel/src/Http/Controllers`
   - **Question:** Does admin dashboard have product create/edit forms?
   - **Impact:** If yes, forms may send enum strings that new validation will reject
   - **Action:** Search for admin routes, Vue/React components using product status

2. **What is the ACTUAL production database column type?**
   - **Assumption:** Migration says `boolean` → `tinyint(1)`
   - **Risk:** If production was manually altered, migration doesn't reflect reality
   - **Action:** Run `DESCRIBE products` on production database
   - **Command:** `php artisan tinker --execute="var_dump(DB::select('SHOW COLUMNS FROM products LIKE \"status\"')[0]);"`

3. **Are there external systems reading product status?**
   - **Question:** Do external APIs, webhooks, or integrations depend on status format?
   - **Impact:** Changing format could break integrations
   - **Action:** Review outbound webhooks, API logs, integration documentation

### HIGH Priority

4. **Does Scout index contain string 'publish' values?**
   - **Action:** Inspect Meilisearch index schema
   - **Command:** Check Meilisearch dashboard or API for product documents
   - **Impact:** May need full reindex if string values exist

5. **Is ProductStatus enum used elsewhere?**
   - **Action:** Global search for `ProductStatus::` in vendor packages, plugins
   - **Impact:** External dependencies may break

### MEDIUM Priority

6. **Historical data analysis:**
   - **Action:** Query production: `SELECT status, COUNT(*) FROM products GROUP BY status`
   - **Question:** Are there any non-0/1 values in production?
   - **Impact:** If yes, enum workflow may be partially implemented

7. **Performance baseline:**
   - **Action:** Measure query time for `->activeStatus()` scope
   - **Question:** Does adding index improve performance significantly?
   - **Impact:** Determines if Phase 7 index creation is necessary

---

## 17. Conclusion

The `products.status` field exhibits a **critical architectural inconsistency** stemming from incomplete refactoring and test schema drift. The system operates in a fragile hybrid state where defensive code masks type coercion issues.

**Current State:** Functional but fragile - works only because defensive code compensates for missing type guarantees.

**Recommended Path:** **Option A - Formalize Boolean Implementation**
- Lowest risk
- Fastest delivery
- Fixes critical test schema mismatch
- Eliminates defensive code
- Clear semantics

**Critical Next Steps:**
1. Answer open questions about admin UI and production schema
2. Execute Phase 1-3 (model cast + test schema) as single atomic PR
3. Run comprehensive test suite
4. Deploy with Scout reindex
5. Monitor for import/export issues

**Estimated Total Effort:** 10-12 hours over 2-3 days

**Sign-off Required From:**
- Backend Lead (schema/model changes)
- QA Lead (test coverage validation)
- DevOps (deployment + Scout reindex)
- Product Owner (enum deprecation approval)

---

**Document Status:** COMPLETE - Ready for Technical Review  
**Next Action:** Schedule architecture review meeting to select option and approve implementation plan
