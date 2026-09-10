# Database Schema — Role & Permission

---

## Tables

The feature uses Spatie Laravel Permission's standard migration schema (5 tables).

### 1. `roles`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | bigint unsigned | PK, Auto-increment | |
| `name` | varchar(255) | NOT NULL | Role slug (e.g. `super_admin`) |
| `guard_name` | varchar(255) | NOT NULL | Default: `api` |
| `display_name` | json | NULLABLE | Translatable field `{"en": "...", "ar": "..."}` |
| `created_at` | timestamp | NULLABLE | |
| `updated_at` | timestamp | NULLABLE | |

**Indexes:** Unique on `(name, guard_name)`

### 2. `permissions`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | bigint unsigned | PK, Auto-increment | |
| `name` | varchar(255) | NOT NULL | Permission slug (e.g. `view_products`) |
| `guard_name` | varchar(255) | NOT NULL | Default: `api` |
| `created_at` | timestamp | NULLABLE | |
| `updated_at` | timestamp | NULLABLE | |

**Indexes:** Unique on `(name, guard_name)`

### 3. `model_has_roles`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `role_id` | bigint unsigned | PK, FK → `roles.id` ON DELETE CASCADE | |
| `model_type` | varchar(255) | PK | Morph type (e.g. `Marvel\Models\User`) |
| `model_id` | bigint unsigned | PK | Morph ID |

### 4. `model_has_permissions`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `permission_id` | bigint unsigned | PK, FK → `permissions.id` ON DELETE CASCADE | |
| `model_type` | varchar(255) | PK | Morph type |
| `model_id` | bigint unsigned | PK | Morph ID |

### 5. `role_has_permissions`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `permission_id` | bigint unsigned | PK, FK → `permissions.id` ON DELETE CASCADE | |
| `role_id` | bigint unsigned | PK, FK → `roles.id` ON DELETE CASCADE | |

---

## Entity Relationship Diagram

```
┌─────────────┐       ┌──────────────────────┐       ┌─────────────┐
│    roles    │       │   role_has_permissions │       │ permissions │
├─────────────┤       ├──────────────────────┤       ├─────────────┤
│ id          │───┐   │ role_id (FK)         │   ┌───│ id          │
│ name        │   │   │ permission_id (FK)   │───┘   │ name        │
│ guard_name  │   │   └──────────────────────┘       │ guard_name  │
│ display_name│   │                                  └─────────────┘
│ created_at  │   │                                        │
│ updated_at  │   │   ┌──────────────────────────┐         │
└─────────────┘   │   │   model_has_roles        │         │
                  └───│ role_id (FK)             │         │
                      │ model_type               │         │
                      │ model_id                 │         │
                      └──────────────────────────┘         │
                                                            │
                      ┌──────────────────────────┐         │
                      │ model_has_permissions    │─────────┘
                      │ permission_id (FK)       │
                      │ model_type               │
                      │ model_id                 │
                      └──────────────────────────┘
```

---

## Key Relationships

| Relationship | Type | Pivot | Foreign Key |
|-------------|------|-------|-------------|
| Role ↔ Permission | Many-to-Many | `role_has_permissions` | `role_id`, `permission_id` |
| User ↔ Role | Morph Many-to-Many | `model_has_roles` | `model_id`, `role_id` |
| User ↔ Permission | Morph Many-to-Many | `model_has_permissions` | `model_id`, `permission_id` |

---

## Cascade Behavior

- **Delete Role:** Cascades from `model_has_roles` and `role_has_permissions`
- **Delete Permission:** Cascades from `model_has_permissions` and `role_has_permissions`
- **Delete User:** Cascades from `model_has_roles` and `model_has_permissions`

---

## Scalability Considerations

| Table | Expected Row Count | Index Strategy | Notes |
|-------|-------------------|----------------|-------|
| `roles` | < 50 | Unique(name, guard) | Static, rarely changes |
| `permissions` | < 100 | Unique(name, guard) | Static, rarely changes |
| `model_has_roles` | = User count | Composite PK | Scales with users |
| `model_has_permissions` | = User count * avg permissions | Composite PK | Scales with users |
| `role_has_permissions` | < 500 | Composite PK | Static |

---

## Migration Source

Standard Spatie Laravel Permission migration (`create_permission_tables.php`), with a custom addition:
- `display_name` JSON column added to `roles` table (not in base Spatie)
