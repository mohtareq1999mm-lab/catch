# Database - Home Feature

No dedicated Home table. The feature composes data from:

| Table | Usage |
|-------|-------|
| `sliders` | Active sliders for hero section |
| `banners` | Active promotional banners |
| `brands` | Active brands list |
| `categories` | Category tree and best categories |
| `products` | Discounted, flash sale, new arrival products |
| `coupons` | Latest valid coupons |
| `flash_sales` | Daily offers and ongoing flash sales |

## Query Patterns

All queries are read-only, cached at 120 min TTL per channel.

| Use Case | Query |
|----------|-------|
| Active sliders | `Slider::active()->ordered()->get()` |
| Category tree | `Category::with('children')->root()->ordered()->get()` |
| Discounted products | `Product::active()->where('has_discount',true)->pricing()->take(10)->get()` |
| New arrivals | `Product::where('created_at', '>=', now()->subDays(15))->take(10)->get()` |
| Valid coupons | `Coupon::valid()->orderByDesc('id')->take(5)->get()` |
