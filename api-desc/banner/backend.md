# Backend - Banner Feature

## Controller - `packages/marvel/src/Http/Controllers/BannerController.php`

| Method | Permission | Description |
|--------|------------|-------------|
| `index` | VIEW_BANNERS | Paginated, active filter, ordered, with products |
| `store` | CREATE_BANNERS | Transactional create + image upload + product sync |
| `show` | VIEW_BANNERS | Find by ID |
| `update` | UPDATE_BANNERS | Transactional update + image replace + product sync |
| `destroy` | DELETE_BANNERS | Soft delete |
| `changeStatus` | UPDATE_BANNERS | Toggle status boolean |
| `reorder` | UPDATE_BANNERS | Sortable setNewOrder |

## Repository - `BannerRepository`

Extends `BaseRepository`. Uses `MediaManager` trait.

| Method | Description |
|--------|-------------|
| `getBanners()` | `with('products')`, optional `active()` scope, `ordered()`, paginate |
| `createBanner(Request)` | Transaction: create → sync products → upload images (desktop + mobile) |
| `updateBanner(Request, $id)` | Transaction: find → update → sync products → replace images |
| `changeStatus($id)` | Toggle `status` boolean |
| `reorder(array $banners)` | `setNewOrder()` from Sortable trait |

## Model - `Banner`

| Feature | Detail |
|---------|--------|
| Table | `banners` |
| Fillable | `title`, `slug`, `description`, `status`, `order` |
| Translatable | `title`, `description` |
| Sortable | `order` column |
| MediaLibrary | Collections: `banners-desktop`, `banners-mobile` |
| SoftDeletes | Yes |
| Relations | `belongsToMany(Product::class, 'banner_product')` |
| Booted | `saving` — auto-generates slug from English title |

## Resource - `BannerResource`

| Field | Source |
|-------|--------|
| `id` | `$this->id` |
| `title` | `$this->title` (translatable) |
| `slug` | `$this->slug` |
| `description` | `$this->description` (translatable) |
| `image.desktop` | `$this->getFirstMediaUrl('banners-desktop')` |
| `image.mobile` | `$this->getFirstMediaUrl('banners-mobile')` |
| `status` | `(bool) $this->status` |
| `products` | `whenLoaded('products')` → `ProductResource::collection()` |

## Form Requests

### BannerCreateRequest

- `title.en`/`title.ar`: required, string, max:255, min:3, UniqueTranslationRule
- `description.en`/`description.ar`: nullable, string, max:500, min:5
- `image_desktop`/`image_mobile`: required, image, mimes:jpeg,png,jpg,gif, max:2048
- `products.*`: exists:products,id

### BannerUpdateRequest

Same rules, all `sometimes`, UniqueTranslationRule ignores current banner ID.

## Translations

All 5 keys present in both EN and AR:

| Key | EN | AR |
|-----|----|----|
| BANNER_CREATED_SUCCESSFULLY | Banner created successfully | تم إنشاء اللافتة بنجاح |
| BANNER_UPDATED_SUCCESSFULLY | Banner updated successfully | تم تحديث اللافتة بنجاح |
| BANNER_DELETED_SUCCESSFULLY | Banner deleted successfully | تم حذف اللافتة بنجاح |
| BANNER_STATUS_CHANGED | Banner status changed successfully | تم تغيير حالة اللافتة بنجاح |
| BANNERS_REORDERED_SUCCESSFULLY | Banners reordered successfully | تمت إعادة ترتيب اللافتات بنجاح |

## Permissions (4)

| Permission Slug | Used On |
|----------------|---------|
| `view-banners` | index, show |
| `create-banners` | store |
| `update-banners` | update, changeStatus, reorder |
| `delete-banners` | destroy |
