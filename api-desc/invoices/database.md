# Database — Dashboard Invoices

> Persistence is invoice-owned (`../../app/Models/Invoice.php`). Dashboard routes read/write the same tables as `api-desc/invoice/database.md`. No dashboard-specific tables.

## Table: `invoices`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | Numeric `{id}` routes |
| uuid | uuid |  | UNIQUE, NOT NULL | `Str::orderedUuid()` on creating (`Invoice.php:67`) |
| order_id | bigint unsigned |  | FK → orders.id RESTRICT | One logical invoice per order (unique partially removed in 2026_07_28 — corrections allow multiple per order via `correction_to_id`) |
| transaction_id | bigint unsigned | NULL | FK → transactions.id NULL ON DELETE | Paid transaction at generation time |
| user_id | bigint unsigned |  | FK → users.id RESTRICT | Owner for PDF auth |
| correction_to_id | bigint unsigned | NULL | FK → invoices.id NULL ON DELETE | Self-ref self for corrections |
| invoice_number | varchar(50) |  | UNIQUE | `INV-YYYY-NNNNNN` via `InvoiceNumberService` |
| invoice_series | varchar(10) | 'INV' |  | `INV`/`CN`/`DN` |
| sequence_number | bigint unsigned |  |  | Per-series per-year gapless |
| sequence_year | year |  |  |  |
| subtotal | decimal(10,3) | 0 |  |  |
| shipping_price | decimal(10,3) | 0 |  |  |
| coupon_discount | decimal(10,3) | 0 |  |  |
| promotion_discount | decimal(10,3) | 0 |  |  |
| total_discount | decimal(10,3) | 0 |  |  |
| total | decimal(10,3) | 0 |  |  |
| amount_paid | decimal(10,3) | 0 |  |  |
| currency | varchar(3) | 'EGP' |  |  |
| payment_method | varchar(30) | NULL |  | `online`/`cod` etc. |
| payment_gateway | varchar(50) | NULL |  | `myfatoorah` etc. |
| status | varchar(20) | 'generated' |  | See `InvoiceStatus` enum |
| data | json |  | NOT NULL | Full frozen snapshot |
| snapshot_hash | varchar(64) | NULL |  | sha256(sorted snapshot json) |
| verification_hash | varchar(64) | NULL |  | sha256(snapshot_hash + app_key) |
| pdf_generated_at | timestamp | NULL |  |  |
| pdf_regenerated_at | timestamp | NULL |  |  |
| pdf_path | varchar(500) | NULL |  | Relative `invoices/{invoice_number}.pdf` on `public` disk |
| pdf_checksum | varchar(64) | NULL |  | md5 of PDF bytes |
| generation_attempts | tinyint unsigned | 0 |  | Incremented on generate/regenerate |
| last_generation_error | text | NULL |  |  |
| is_correction | boolean | false |  |  |
| correction_reason | varchar(500) | NULL |  |  |
| corrected_at | timestamp | NULL |  |  |
| cancelled_at | timestamp | NULL |  |  |
| cancellation_reason | varchar(500) | NULL |  |  |
| generated_at | timestamp | CURRENT_TIMESTAMP |  |  |
| generated_by | varchar(50) | 'system' |  |  |
| verified_at | timestamp | NULL |  | First verify only |
| downloaded_at | timestamp | NULL |  | First download only |
| printed_at | timestamp | NULL |  |  |
| archived_at | timestamp | NULL |  |  |
| last_verified_at | timestamp | NULL |  | Every verify |
| verify_count | smallint unsigned | 0 |  |  |
| created_at | timestamp | NULL |  |  |
| updated_at | timestamp | NULL |  |  |

### Indexes

| Index | Columns | Type |
|-------|---------|------|
| PRIMARY | id | PK |
| invoices_uuid_unique | uuid | UNIQUE |
| uq_invoices_invoice_number | invoice_number | UNIQUE |
| uq_invoices_order_id | order_id | UNIQUE (legacy; partially retained — correction flow uses same order_id with `correction_to_id` distinction; see migration 000006) |
| idx_invoices_user_id | user_id | INDEX |
| idx_invoices_status | status | INDEX |

---

## Table: `invoice_sequences`

| Column | Type | Constraints |
|--------|------|-------------|
| series | varchar(10) | PK part |
| sequence_year | year | PK part |
| last_sequence | bigint unsigned | Default 0 |
| created_at | timestamp |  |
| updated_at | timestamp |  |

Composite PK (series, sequence_year). `lockForUpdate` in `InvoiceNumberService` for gapless numbering.

---

## Table: `invoice_timeline`

| Column | Type |
|--------|------|
| id | PK |
| invoice_id | FK → invoices.id CASCADE |
| event | varchar(50) (`generated`, `pdf_regenerated`, `verified`, `downloaded`, `corrected`, `cancelled`, etc.) |
| old_status | varchar(20) NULL |
| new_status | varchar(20) NULL |
| actor_type | morph NULL |
| actor_id | bigint unsigned NULL |
| metadata | json NULL |
| ip_address | varchar(45) NULL |
| created_at | timestamp |
| updated_at | timestamp |

Indexes: invoice_id, event, (actor_type, actor_id).

---

## Table: `debit_notes`

| Column | Type |
|--------|------|
| id | PK |
| uuid | UNIQUE |
| invoice_id | FK → invoices.id CASCADE |
| debit_note_number | UNIQUE |
| debit_note_series | Default 'DN' |
| sequence_number |  |
| sequence_year |  |
| reason | varchar(500) |
| type | Default 'correction' |
| amount | decimal(10,3) |
| currency | Default 'EGP' |
| created_by | FK → users.id SET NULL |
| line_items | json NULL |
| notes | text NULL |
| issued_at | CURRENT_TIMESTAMP |
| created_at |  |
| updated_at |  |

---

## Table: `credit_notes` (not directly in this 10-route group, but sibling)

Same structure with `CN` series and optional `refund_transaction_id`.

---

## Media / Storage

- Disk: `public` (`../../storage/app/public`, url `/storage`)
- PDF path: `storage/app/public/invoices/{invoice_number with / replaced by -}.pdf`
- Resource `download_url`/`view` both point to `invoices/{pdf_path}`; `AdminInvoiceResource` builds `url('/api/v1/invoices/' + uuid + '/download')` for the authenticated download endpoint (this group) vs signed routes for customers.

## Soft Deletes

**None.** Invoices are immutable financial records; cancellation/correction creates new state/row, never soft-deletes.

## Migrations

| File | Tables |
|------|--------|
| `2026_07_16_000001_create_invoice_sequences_table.php` | invoice_sequences |
| `2026_07_16_000002_create_invoices_table.php` | invoices |
| `2026_07_27_082000_add_uuid_and_verification_to_invoices_table.php` | invoices (uuid, verification_hash) |
| `2026_07_28_000001_create_invoice_timeline_table.php` | invoice_timeline |
| `2026_07_28_000005_add_invoice_lifecycle_columns.php` | invoices (lifecycle timestamps) |
| `2026_07_28_000006_remove_unique_order_id_from_invoices.php` | invoices (relax order_id unique for corrections) |

## Query Patterns (dashboard group)

- `index` uses `Invoice::with(['order','user'])` + `when()` filters + bounded `paginate(min(limit,100))` — avoids N+1, caps page size.
- `show`/`showByUuid` use `with(['order.orderItems','transaction','user'])` — eager loads for resource.
- `verify` loads `order`/`user` then hash compare; increments verify counters in same request.
- `download`/`view` load `order` only (minimal) then `Storage::disk('public')->exists()` before streaming.
- Mutations (`correct`/`cancel`/`debit-note`/`regenerate`) use `lockForUpdate()` inside `DB::transaction` to prevent concurrent status races.
