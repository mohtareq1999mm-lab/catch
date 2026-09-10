# Test Cases - Search Feature

## Existing Test (Likely Failing)

**File:** `tests/Feature/FastShippingControllerTest.php` line 943

```php
public function search_endpoint_works_with_channel_header()
{
    $response = $this->getJson('/api/v1/general/search', [
        'X-Channel' => 'fast-shipping',
    ]);
    $response->assertOk();  // Will fail: route not registered → 404
}
```

## Recommended Tests

| # | Test | Description |
|---|------|-------------|
| FT-001 | Search with query term | Returns results |
| FT-002 | Search with empty query | Validation error or all results |
| FT-003 | Search filtered by type | Only requested types returned |
| FT-004 | Search with no results | Empty data array |
| FT-005 | Search rate limiting | 429 after 30 requests/min |
| FT-006 | Unauthenticated access | 200 (public) |
| FT-007 | Search with special characters | Safe handling |
