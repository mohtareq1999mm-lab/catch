# Governorate Module — Database (Public API)

## Tables

### `governorates`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| country_id | int(11) | FK to countries |
| name | varchar(255) | Governorate name (translatable) |
| slug | varchar(255) | URL slug |
| status | tinyint(1) | Active flag |
| is_fast_shipping_enabled | tinyint(1) | Fast shipping availability |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- Primary: `id`
- Index: `status`
- Index: `country_id`

## Query Patterns

### List Active Governorates
```sql
SELECT * FROM `governorates`
WHERE `status` = 1
ORDER BY `id` DESC;
```

## N+1 Prevention

- **List:** 1 query, no relations loaded — no N+1 risk

## Performance

- **List governorates:** 1 query, <2ms
- **No pagination** — dataset is small (max ~50 governorates)
- **No caching** — every request hits DB
