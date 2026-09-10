# Category Products Count Mismatch

**Date:** 2026-07-23

---

## What Happens To Frontend:

When you call `GET /api/v1/general/categories/{slug}` the response shows:

- `products_count` is 3
- `products` array only has 1 item

Example:

```json
{
  "data": {
    "id": 5,
    "name": "Men Perfume",
    "slug": "men-perfume-77",
    "products_count": 3,
    "products": [
      {
        "id": 110,
        "name": "Some Product"
      }
    ]
  }
}
```

The category says it has 3 products but you only get 1 product in the list. Your UI shows a count badge with the wrong number.

---

## What Changed In The API

Before the fix:
- `products_count` counted all products attached to the category (including fast shipping products)
- `products` array only returned non-fast-shipping products

After the fix:
- `products_count` now only counts the same products that appear in the `products` array
- If no products qualify, `products_count` is 0 and `products` key is omitted from response

---

## What You Should See Now

- `products_count` always matches the actual length of `products` array
- If you see `products_count: 0`, there are no `products` in the response
- If you see `products_count: 3`, you will get exactly 3 items in the `products` array
