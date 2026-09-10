# Database - Notification Feature

## Table: `notifications` (Laravel built-in)

| Column | Type | Constraints |
|--------|------|------------|
| `id` | uuid | PK |
| `type` | string | Notification class FQN |
| `notifiable_type` | string | Morphs — e.g. `Marvel\Database\Models\User` |
| `notifiable_id` | bigint | Morphs — user ID |
| `data` | text | JSON — title, message, icon, resource_type, resource_id, action_url |
| `read_at` | timestamp | nullable |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

## Key Queries

| Use Case | Pattern |
|----------|---------|
| List (paginated) | `SELECT * FROM notifications WHERE notifiable_type = ? AND notifiable_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?` |
| Unread | `SELECT * FROM notifications WHERE notifiable_type = ? AND notifiable_id = ? AND read_at IS NULL ORDER BY created_at DESC` |
| Mark as read | `UPDATE notifications SET read_at = NOW() WHERE id = ? AND notifiable_id = ?` |
| Mark all read | `UPDATE notifications SET read_at = NOW() WHERE notifiable_id = ? AND read_at IS NULL` |
| Delete single | `DELETE FROM notifications WHERE id = ? AND notifiable_id = ?` |
| Delete all | `DELETE FROM notifications WHERE notifiable_id = ?` |

## Indexes

- Primary: `id` (uuid)
- Polymorphic index: `(notifiable_type, notifiable_id)` — created by `morphs()` in migration
- No additional custom indexes
