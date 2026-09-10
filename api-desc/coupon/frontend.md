# Coupon Module — Frontend Integration Guide

## Public Endpoints

---

### GET /api/v1/general/coupons

Fetch list of valid coupons for display (e.g., banners, promo sections).

**Request:**
```js
fetch('/api/v1/general/coupons?limit=10')
  .then(res => res.json())
  .then(data => console.log(data.data));
// Returns: [{ id, name, slug, image: { desktop, mobile }, borderColor, borderless }]
```

**Response Schema:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Summer 20% Off",
      "slug": "summer-20-off",
      "image": {
        "desktop": "https://example.com/storage/coupons/desktop.jpg",
        "mobile": "https://example.com/storage/coupons/mobile.jpg"
      },
      "borderColor": "#FF0000",
      "borderless": false
    }
  ]
}
```

**Usage:**
- Display as promotional banner cards
- Use `borderColor` for card accent color
- Use `borderless` toggle for border style
- Show desktop/mobile images responsively

---

### POST /api/v1/general/coupons/apply

Apply a coupon code to the current user's cart.

**Request:**
```js
fetch('/api/v1/general/coupons/apply', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer {token}'
  },
  body: JSON.stringify({ coupon_code: 'SUMMER20' })
})
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      // Coupon applied — update cart summary
    } else {
      // Handle error (already applied, invalid, expired, etc.)
    }
  });
```

**Error States:**
| HTTP Status | Message | Action |
|-------------|---------|--------|
| 400 | Coupon already applied | Show "Already applied" message |
| 400 | Coupon not found | Show "Invalid code" error |
| 400 | Coupon has expired | Show "Expired" message |
| 400 | Usage limit reached | Show "Limit reached" message |
| 400 | Product not eligible | Show "Not applicable to cart" message |
| 401 | - | Redirect to login |

---

## Admin Endpoints

### GET /api/v1/coupons

Admin coupon listing (paginated, filterable).

**Query Parameters:** `page`, `limit`, `search`, `status`, `valid`, `order_by`, `sort`

### POST /api/v1/coupons

Create coupon (multipart/form-data with images).

### PUT /api/v1/coupons/{id}

Update coupon (multipart/form-data).

### DELETE /api/v1/coupons/{id}

Delete coupon.

---

## Frontend Patterns

### Loading State
```jsx
function CouponList() {
  const [loading, setLoading] = useState(true);
  const [coupons, setCoupons] = useState([]);

  useEffect(() => {
    fetch('/api/v1/general/coupons?limit=10')
      .then(res => res.json())
      .then(data => {
        setCoupons(data.data || []);
        setLoading(false);
      })
      .catch(() => setLoading(false));
  }, []);

  if (loading) return <Skeleton variant="rectangular" height={200} />;
  // ...
}
```

### Empty State
```jsx
{coupons.length === 0 && (
  <EmptyState
    icon={<TagIcon />}
    title="No coupons available"
    description="Check back later for new offers"
  />
)}
```

### Error State
```jsx
{couponError && (
  <Alert severity="error">
    {couponError === 'already_applied'
      ? 'This coupon is already applied to your cart'
      : 'Invalid coupon code. Please try again.'}
  </Alert>
)}
```

### Apply Coupon UI
```jsx
function CouponInput() {
  const [code, setCode] = useState('');
  const [applying, setApplying] = useState(false);

  const handleApply = async () => {
    setApplying(true);
    try {
      const res = await fetch('/api/v1/general/coupons/apply', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ coupon_code: code }),
      });
      const data = await res.json();
      if (data.success) {
        // Update cart state with discount
      } else {
        // Show error message
      }
    } finally {
      setApplying(false);
    }
  };

  return (
    <div>
      <TextField value={code} onChange={e => setCode(e.target.value)} />
      <Button onClick={handleApply} disabled={applying || !code}>
        {applying ? 'Applying...' : 'Apply'}
      </Button>
    </div>
  );
}
```

### Admin CRUD Table
```jsx
// Columns: Code, Name, Discount, Type, Start Date, End Date, Used/Limiter, Status, Actions
// Actions: Edit, Delete
// Filters: Search, Status, Validity
// Pagination: Server-side (page, limit)
```

### Admin Create/Edit Form
```jsx
// Fields:
//   name (multilingual: en, ar)
//   image-desktop (file upload)
//   image-mobile (file upload)
//   discount_type (select: percentage, fixed_rate, free_shipping)
//   discount (number)
//   max_discount_amount (conditional: show when discount_type=percentage)
//   product_ids (multi-select, optional)
//   start_date (date picker)
//   end_date (date picker)
//   limiter (number, optional)
//   status (toggle)
//   border_color (color picker)
//   borderless (toggle)
```

### Key Considerations
1. **Translatable names** — Send `name` as `{"en": "...", "ar": "..."}`
2. **Image upload** — Use `multipart/form-data` for create/update
3. **Conditional fields** — Show/hide `max_discount_amount` based on `discount_type`
4. **Date format** — Send dates in `Y-m-d` format
5. **Empty products** — Empty `product_ids` means coupon applies to all products
6. **Border styling** — Use `borderColor` and `borderless` for card styling
