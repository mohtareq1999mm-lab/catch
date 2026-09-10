# Database — Admin Users

## Table: `users`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| name | varchar(255) | | | |
| email | varchar(255) | | UNIQUE | |
| email_verified_at | timestamp | NULL | | |
| password | varchar(255) | | | Hashed |
| remember_token | varchar(100) | NULL | | |
| type | varchar(255) | 'user' | | `user` or `admin` |
| is_active | tinyint(1) | 1 | | |
| phone_number | varchar(255) | NULL | UNIQUE | |
| shop_id | bigint unsigned | NULL | FK → shops.id | |
| deleted_at | timestamp | NULL | | Soft deletes |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes
- Primary: `id`
- Unique: `email`, `phone_number`
- Foreign: `shop_id` → `shops.id`

### Global Scope
- All queries ordered by `updated_at DESC`

## Related Spatie Tables

The following Spatie Permission tables store role/permission data for users:

| Table | Purpose |
|-------|---------|
| `roles` | Role definitions (super_admin, customer, staff, store_owner, editor) |
| `permissions` | Permission definitions (view-users, create-user, etc.) |
| `model_has_roles` | User-role assignments (model_type = `Marvel\Database\Models\User`) |
| `model_has_permissions` | User-permission assignments |
| `role_has_permissions` | Role-permission mappings |

### Spatie Guard
- `guard_name` = `'api'` for all roles and permissions

## Other Related Tables

| Table | Relation | Column |
|-------|----------|--------|
| `address` | HasMany | `customer_id` → `users.id` |
| `user_profiles` | HasOne | `customer_id` → `users.id` |
| `wallets` | HasOne | `customer_id` → `users.id` |
| `media` | MorphMany | model_type=`Marvel\Database\Models\User` |
| `password_resets` | Used by forget-password | `email` |
| `personal_access_tokens` | Sanctum tokens | `tokenable_type`=`Marvel\Database\Models\User` |
| `activity_log` | Activity logs | subject_type=`Marvel\Database\Models\User` |

## Soft Deletes
The `users` table uses Laravel's `SoftDeletes` trait. When a user is deleted:
- `deleted_at` is set (not null)
- Sanctum tokens are revoked
- The user is not visible in normal queries (global scope)

## Fillable Mass Assignment
```php
protected $fillable = [
    'name', 'email', 'password', 'is_active', 'type',
    'email_verified_at', 'phone_number', 'remember_token',
];
```
