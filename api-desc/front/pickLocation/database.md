# Pickup Location Module — Database (Public API)

## Tables

### `pickup_locations`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| store_name | varchar(255) | Location display name |
| address | text | Full address |
| phone | varchar(255), nullable | Contact phone |
| email | varchar(255), nullable | Contact email |
| latitude | varchar(255), nullable | Map latitude |
| longitude | varchar(255), nullable | Map longitude |
| working_hours | json, nullable | Hours per day |
| status | tinyint(1) | Active flag |
| display_order | int(11) | Sort priority |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp, nullable | Soft delete |

**Indexes:**
- Primary: `id`
- Index: `status`
- Index: `display_order`

## Query Patterns

### List Active Locations
```sql
SELECT * FROM `pickup_locations`
WHERE `status` = 1 AND `deleted_at` IS NULL
  [AND `store_name` LIKE '%search%']
ORDER BY `display_order` ASC, `id` ASC
LIMIT ? OFFSET ?;
```

### Find Active Location by ID
```sql
SELECT * FROM `pickup_locations`
WHERE `id` = ? AND `status` = 1 AND `deleted_at` IS NULL
LIMIT 1;
```

## N+1 Prevention

- **List:** 1 query, no relations loaded — no N+1 risk
- **Show:** 1 query — no N+1 risk

## Performance

- **List locations:** 1 query, <5ms
- **Show location:** 1 query, <2ms
- **No caching** — every request hits DB
