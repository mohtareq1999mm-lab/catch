# Product Status Import Contract Verification

## Current parseBoolean() Implementation

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
        return in_array(strtolower($value), ['1']);
    }
    return false;
}
```

## Behavior Matrix

| Input | Type | Result | Notes |
|-------|------|--------|-------|
| `1` | int | `true` | ✅ Accepted |
| `0` | int | `false` | ✅ Accepted |
| `"1"` | string | `true` | ✅ Accepted (Excel/CSV normalization) |
| `"0"` | string | `false` | ⚠️ **REJECTED** - Falls through to `return false` |
| `true` | bool | `true` | ✅ Accepted |
| `false` | bool | `false` | ✅ Accepted |
| `"true"` | string | `false` | ❌ Rejected (legacy removed) |
| `"false"` | string | `false` | ❌ Rejected (legacy removed) |
| `"publish"` | string | `false` | ❌ Rejected (legacy removed) |
| `"yes"` | string | `false` | ❌ Rejected (legacy removed) |
| `"approved"` | string | `false` | ❌ Rejected (legacy removed) |
| `null` | null | `false` | ⚠️ Falls through to default |
| `""` | string | `false` | ⚠️ Empty string rejected |
| `2` | int | `false` | ❌ Invalid integer |
| `"draft"` | string | `false` | ❌ Legacy value |

## CRITICAL BUG FOUND: String "0" Handling

**Problem:** The current implementation accepts string `"1"` but REJECTS string `"0"`.

**Root Cause:** 
```php
if (is_string($value)) {
    return in_array(strtolower($value), ['1']);  // Only checks for '1'
}
return false;  // String "0" falls through to here
```

**Impact:**
- Excel/CSV exports produce `"0"` and `"1"` as strings
- Import accepts `"1"` → Active ✅
- Import rejects `"0"` → **Silently converts to inactive** ❌

This breaks the round-trip requirement:
```
Active Product → Export "1" → Import → Active ✅
Inactive Product → Export "0" → Import → **Falls through to false** ⚠️
```

## Required Fix

```php
if (is_string($value)) {
    return in_array(strtolower($value), ['1', '0']) ? $value === '1' : false;
}
```

Or more explicit:
```php
if (is_string($value)) {
    $lower = strtolower($value);
    if ($lower === '1') {
        return true;
    }
    if ($lower === '0') {
        return false;
    }
    return false; // Invalid string
}
```

## Validation Contract

After fix, the import should:
- Accept: `1`, `0`, `"1"`, `"0"`, `true`, `false`
- Reject (default to false): legacy strings, null, invalid integers
- **Silent conversion to false is acceptable for invalid values** per existing import error architecture
