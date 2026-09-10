# Attribute Module — Database Schema

## Table: `attributes`

**Migration:** `2020_06_02_051901_create_marvel_tables.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `slug` | string | NOT NULL |
| `name` | string | NOT NULL (JSON for translations) |
| `created_at` | timestamp | NULLABLE |
| `updated_at` | timestamp | NULLABLE |

**Model:** `Marvel\Database\Models\Attribute`
- Traits: `HasTranslations`, `Sluggable`
- Translatable: `name`
- Fillable: `name`, `slug`

## Table: `attribute_values`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `slug` | string | NOT NULL |
| `attribute_id` | bigint unsigned | FK → attributes.id ON DELETE CASCADE |
| `value` | string | NOT NULL (JSON for translations) |
| `created_at` | timestamp | NULLABLE |
| `updated_at` | timestamp | NULLABLE |

**Unique:** `UNIQUE (attribute_id, slug)` — prevents duplicate slugs per attribute

**Model:** `Marvel\Database\Models\AttributeValue`
- Traits: `HasTranslations`, `Sluggable`
- Translatable: `value`
- Fillable: `value`, `slug`, `attribute_id`

## Table: `attribute_product` (pivot)

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `attribute_value_id` | bigint unsigned | FK → attribute_values.id ON DELETE CASCADE |
| `product_variant_id` | bigint unsigned | FK → product_variants.id ON DELETE CASCADE |

## Foreign Keys

| FK | ON DELETE |
|----|-----------|
| `attribute_values.attribute_id` → `attributes.id` | CASCADE |
| `attribute_product.attribute_value_id` → `attribute_values.id` | CASCADE |
| `attribute_product.product_variant_id` → `product_variants.id` | CASCADE |

## Cascade Chain

```
DELETE attribute (id=1)
  → attribute_values (attribute_id=1) ALL DELETED
    → attribute_product (attribute_value_id IN ...) ALL DELETED
```

## Comparison with Brands/Categories

| Feature | Attributes | Brands | Categories |
|---------|-----------|--------|------------|
| Delete type | Hard delete | Soft delete | Soft delete |
| Cascade | CASCADE all | Preserved | RESTRICT (parent) |
| Translations | name, value | name, details | name, details |
| Media | None | Desktop + Mobile | Desktop + Mobile |
| Hierarchy | None | None | Parent-child |
| Sortable | None | Spatie SortableTrait | None |
