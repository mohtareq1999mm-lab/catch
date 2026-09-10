# Backend - Slider Feature

## Admin Controller - `packages/marvel/src/Http/Controllers/SliderController.php`

| Method | Permission | Description |
|--------|------------|-------------|
| `index` | VIEW_SLIDER | Paginated, filterable (active), sortable (order/column) |
| `store` | CREATE_SLIDER | Transactional create + image upload + product sync |
| `show` | VIEW_SLIDER | Find by ID, load products |
| `update` | UPDATE_SLIDER | Transactional update + image replace + product sync |
| `destroy` | DELETE_SLIDER | Soft delete |
| `changeStatus` | UPDATE_SLIDER | Toggle status, load products |
| `reorder` | UPDATE_SLIDER | Sortable setNewOrder |

## Repository - `SliderRepository`

| Method | Description |
|--------|-------------|
| `getSliders(Request)` | with active filter, optional orderBy(column, dir), fallback ordered(), paginate |
| `createSlider(Request)` | Transaction: create → upload images → sync products |
| `updateSlider(Request, $id)` | Transaction: find → update → replace images → sync products |
| `changeStatus($id)` | Toggle `status` boolean |
| `reorder(array $ids)` | `setNewOrder()` from Sortable trait |

## Model - `Slider`

| Feature | Detail |
|---------|--------|
| Table | `sliders` |
| Fillable | `title`, `slug`, `order`, `status` |
| Translatable | `title` |
| Sortable | `order` column |
| MediaLibrary | Collections: `sliders-desktop`, `sliders-mobile` (also fallback `slider-image-desktop`, `slider-image-mobile`) |
| SoftDeletes | Yes |
| Relations | `belongsToMany(Product::class, 'slider_product')` |
| Booted | `saving` — auto-generates slug from English title |

## Resource - `SliderResource`

| Field | Detail |
|-------|--------|
| `id` | `$this->id` |
| `title` | On `sliders.index`: single locale string. On others: full translations array |
| `slug` | `$this->slug` |
| `status` | `(bool) $this->status` |
| `order` | `$this->order` |
| `image.desktop` | `getFirstMediaUrl('sliders-desktop')` fallback `slider-image-desktop` |
| `image.mobile` | `getFirstMediaUrl('sliders-mobile')` fallback `slider-image-mobile` |
| `products` | `whenLoaded` → id, name, slug, status, image.thumbnail |

## Form Requests

### SliderCreateRequest

- `title.en`/`title.ar`: required, string, UniqueTranslationRule
- `image_desktop`/`image_mobile`: required, image, mimes:jpeg,png,jpg,gif, max:2048
- `products.*`: exists:products,id

### SliderUpdateRequest

Same rules, all `sometimes`, UniqueTranslationRule ignores current ID.

## Permissions (4)

| Permission Slug | Used On |
|----------------|---------|
| `view-slider` | index, show |
| `create-slider` | store |
| `update-slider` | update, changeStatus, reorder |
| `delete-slider` | destroy |

## Public Controller - `app/Http/Controllers/Api/General/SliderController.php`

- `index`: returns active sliders (optional `slug` filter), through `SliderService`
- `show($slug)`: finds by slug, enriches products with pricing via `ProductPricingService`
