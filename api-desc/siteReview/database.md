# Database — Site Reviews Module

## Table: `site_reviews`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| user_id | bigint unsigned | | NOT NULL, FK → users.id, ON DELETE CASCADE | Review author (customer) |
| rating | tinyint unsigned | | NOT NULL | 1–5 (validated at app layer) |
| title | varchar(191) | NULL | | Optional |
| comment | text | | NOT NULL | |
| status | varchar(20) | `pending` | NOT NULL | `pending` / `approved` / `rejected` |
| moderated_by | bigint unsigned | NULL | FK → users.id, ON DELETE SET NULL | Admin who moderated |
| moderated_at | timestamp | NULL | | When it was moderated |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes

| Name | Columns | Purpose |
|------|---------|---------|
| PRIMARY | id | |
| `site_reviews_user_id_foreign` | user_id | FK (auto-created by `foreignId()->constrained()`) |
| `site_reviews_moderated_by_foreign` | moderated_by | FK (auto-created) |
| `idx_site_reviews_status` | status | Fast moderation filtering (`?status=` admin filter) |
| `idx_site_reviews_user_id` | user_id | Redundant with FK index (harmless, explicit) |
| `idx_site_reviews_moderated_by` | moderated_by | Redundant with FK index (harmless, explicit) |

## Migration File

`database/migrations/2026_08_10_000001_create_site_reviews_table.php`

```php
Schema::create('site_reviews', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->unsignedTinyInteger('rating');
    $table->string('title', 191)->nullable();
    $table->text('comment');
    $table->string('status', 20)->default('pending')->index('idx_site_reviews_status');
    $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('moderated_at')->nullable();
    $table->timestamps();

    $table->index('user_id', 'idx_site_reviews_user_id');
    $table->index('moderated_by', 'idx_site_reviews_moderated_by');
});
```

### Referential Actions

- `user_id` → `users.id` **ON DELETE CASCADE** — deleting a customer removes their site reviews.
- `moderated_by` → `users.id` **ON DELETE SET NULL** — deleting an admin keeps the review but clears the moderator link (moderated_at is preserved).

## Fillable Mass Assignment

```php
protected $fillable = [
    'user_id',
    'rating',
    'title',
    'comment',
    'status',
    'moderated_by',
    'moderated_at',
];
```

## Related Tables

| Table | Relation | Column |
|-------|----------|--------|
| `users` | BelongsTo (`user()`) | `site_reviews.user_id` → `users.id` |
| `users` | BelongsTo (`moderator()`) | `site_reviews.moderated_by` → `users.id` |

## Notes / Constraints Gaps

- **No database-level CHECK on `rating` 1–5** — enforced only by `CreateSiteReviewRequest` (app layer). A direct DB insert could store any tinyint 0–255.
- **No unique constraint** — a customer can submit multiple site reviews. This is intentional (website feedback, not per-product review); documented for awareness.
- **No soft deletes** — `deleted_at` is not present. Reviews are permanent once created (or removed via `user_id` cascade).
- **Status transitions** are enforced in the service (`moderate()`), not by a DB trigger.
- The explicit `idx_site_reviews_user_id` / `idx_site_reviews_moderated_by` indexes duplicate the FK indexes auto-created by Laravel — harmless but redundant.
