# Backend - Search Feature

## Key Files

### Controller - `app/Http/Controllers/Api/General/SearchController.php`

```php
class SearchController extends Controller
{
    use ApiResponse;
    private SearchService $searchService;

    public function __construct(SearchService $searchService) { ... }

    public function index(Request $request)
    {
        $data = $this->searchService->search($request);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $data);
    }
}
```

### Service - `app/Services/General/SearchService.php`

```php
class SearchService
{
    public function search(Request $request)
    {
        return [];  // STUB — no implementation
    }
}
```

### Rate Limiter (defined but unused)

```php
RateLimiter::for('search', fn($request) => Limit::perMinute(30)->by($request->ip()));
```

## Existing Search Infrastructure (not used by Search feature)

| Model | Scope | Search Method |
|-------|-------|---------------|
| Product | `scopeSearch` | Translatable LIKE + Scout (Meilisearch) |
| Brand | `scopeSearch` | Translatable LIKE |
| Category | `scopeSearch` | Translatable LIKE |
| Banner | `scopeSearch` | Translatable LIKE |
| Slider | `scopeSearch` | Translatable LIKE |
| Coupon | `scopeSearch` | LIKE |
| FlashSale | `scopeSearch` | LIKE |
| Promotion | `scopeSearch` | LIKE |
| Shop | `scopeSearch` | LIKE |
