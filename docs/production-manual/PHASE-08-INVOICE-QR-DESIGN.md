# PHASE 8: INVOICE QR DESIGN

> Production Operations Manual — QR Code Architecture & Invoice Verification
> Last Updated: 2026-07-28

---

## TABLE OF CONTENTS

1. [Current State: Cashier QR Only](#current-state-cashier-qr-only)
2. [Invoice Verification Endpoint](#invoice-verification-endpoint)
3. [Verification Hash Algorithm](#verification-hash-algorithm)
4. [Invoice Resource QR Content](#invoice-resource-qr-content)
5. [THE GAP: No QR on Invoice](#the-gap-no-qr-on-invoice)
6. [Recommended QR Design for Invoice PDF](#recommended-qr-design-for-invoice-pdf)
7. [Security Architecture](#security-architecture)
8. [Verification Flow Diagram](#verification-flow-diagram)
9. [Implementation Roadmap](#implementation-roadmap)
10. [Edge Cases & Threat Model](#edge-cases--threat-model)

---

## Current State: Cashier QR Only

Source: `app/Services/Gateway/CashierQrService.php`

### CashierQrService

```php
class CashierQrService
{
    public function generateSvg(Transaction $transaction): string
    {
        $payload = json_encode([
            'transaction' => $transaction->uuid,
        ]);
        // ... generates QR code using chillerlan/php-qrcode
    }
}
```

**Purpose**: Generates QR code for cashier payment verification only. The QR encodes the transaction UUID.

**Usage**: Not used in invoice flow at all. Separate concern for MyFatoorah cashier payments.

**Library**: `chillerlan/php-qrcode` — outputs SVG format.

### Current QR Properties

| Property | Value |
|---|---|
| Payload | `{"transaction": "{uuid}"}` |
| Encoding | JSON |
| Output | SVG (inline XML, base64 data URI available) |
| ECC Level | L (Low — 7% recovery) |
| Scale | `max(1, size/50)` |

---

## Invoice Verification Endpoint

Source: `routes/api.php:125`, `InvoiceController::verify()`

### Route Definition

```php
Route::get('verify/{uuid}', [InvoiceController::class, 'verify'])
    ->middleware('throttle:60,1');
```

### Endpoint Details

| Property | Value |
|---|---|
| Method | `GET` |
| Path | `/api/v1/general/invoices/verify/{uuid}` |
| Authentication | **NOT required** (public endpoint) |
| Rate Limit | 60 requests per minute per IP |
| Controller | `InvoiceController::verify()` |

### Verification Response (Success — 200)

```json
{
  "success": true,
  "message": "Data fetched successfully",
  "data": {
    "authentic": true,
    "invoice": {
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "invoice_number": "INV-2026-000042",
      "status": "ready",
      "total": 1500.00,
      "currency": "EGP",
      "snapshot_hash": "abc123...",
      "verification_hash": "def456...",
      "generated_at": "2026-07-28T10:00:00+00:00",
      "verification_url": "https://shop.example.com/api/v1/general/invoices/verify/550e8400-e29b-41d4-a716-446655440000"
    },
    "order": {
      "id": 123,
      "order_number": "ORD-2026-000042",
      "status": "processing",
      "payment_status": "paid",
      "fulfillment_status": "pending"
    },
    "qr_content": "https://shop.example.com/api/v1/general/invoices/verify/550e8400-e29b-41d4-a716-446655440000"
  }
}
```

### Verification Response (Tampered — 409)

```json
{
  "success": false,
  "message": "Invoice verification failed",
  "data": {
    "authentic": false,
    "tampered": true
  }
}
```

### Verification Response (Not Found — 404)

```json
{
  "success": false,
  "message": "Not found",
  "data": null
}
```

---

## Verification Hash Algorithm

Source: `InvoiceService::computeVerificationHash()` (line 200)

### HMAC Computation

```php
private function computeVerificationHash(string $snapshotHash): string
{
    $secret = config('app.key', 'default-secret');
    return hash('sha256', $snapshotHash . $secret);
}
```

### Algorithm Details

```
verification_hash = SHA-256( snapshot_hash || app_key )
```

Where:
- `snapshot_hash` = SHA-256 of the sorted snapshot JSON (from `SnapshotIntegrityService`)
- `app_key` = `config('app.key')` — the application encryption key
- `||` = string concatenation
- Output = 64-character hex string

### Security Properties

1. **Snapshot Integrity**: The `snapshot_hash` is computed deterministically from the entire invoice data. Any modification to the data (items, prices, customer info, etc.) will produce a different `snapshot_hash`, which will produce a different `verification_hash`.

2. **Key Secrecy**: Without knowledge of `app.key`, an attacker cannot compute a valid `verification_hash` for a modified invoice. The `app.key` is the single secret that binds the verification.

3. **Replay Prevention**: If an attacker copies a valid `verification_hash` to a different invoice, the `snapshot_hash` won't match, and `hash_equals()` catches the mismatch.

4. **Timing Attack Protection**: Uses `hash_equals()` for comparison, which runs in constant time regardless of how many characters match.

### Limitations

- **No Key Rotation**: If `app.key` is changed (e.g., via `php artisan key:generate`), ALL existing verification hashes become invalid. There is no support for key versioning or multiple verification keys.
- **Not True HMAC**: This uses string concatenation (`$snapshotHash . $secret`) rather than a proper HMAC construction like `hash_hmac('sha256', $snapshotHash, $secret)`. While functionally similar, the proper HMAC construction is cryptographically stronger (prevents length-extension attacks on the inner hash). However, since the input is a hash (fixed 64 chars) rather than arbitrary data, the risk is negligible.

---

## Invoice Resource QR Content

Source: `app/Http/Resources/Invoice/InvoiceResource.php:49`

### Current `qr_content` Field

```php
'qr_content' => $this->when($this->uuid, function () {
    return [
        'uuid' => $this->uuid,
        'invoice_number' => $this->invoice_number,
        'verification_hash' => $this->verification_hash,
        'issued_at' => $this->generated_at?->toIso8601String(),
        'verification_url' => url('/api/v1/general/invoices/verify/' . $this->uuid),
    ];
}),
```

### Returned Payload Shape

```json
{
  "qr_content": {
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "invoice_number": "INV-2026-000042",
    "verification_hash": "abc123def456...",
    "issued_at": "2026-07-28T10:00:00+00:00",
    "verification_url": "https://shop.example.com/api/v1/general/invoices/verify/550e8400-e29b-41d4-a716-446655440000"
  }
}
```

**Note**: This is returned as JSON in the API response, NOT embedded in a QR code image on the invoice PDF. This is metadata the frontend can use to generate its own QR.

---

## THE GAP: No QR on Invoice

### Current Invoice PDF

| Feature | Status |
|---|---|
| PDF stored at `pdf_path` | Only when `GenerateInvoicePdfJob` produces one (currently placeholder) |
| QR code on PDF | **NOT implemented** |
| Verification URL in invoice data | Available in snapshot `metadata`, but not rendered |
| QR content returned via API | Yes (as `qr_content` in `InvoiceResource`) |

### The Problem

The invoice PDF itself has no QR code. When printed or downloaded, there is no scannable code that a customer or auditor can use to verify the invoice's authenticity. The verification infrastructure exists (HMAC + endpoint) but is not accessible from the physical/digital invoice document.

---

## Recommended QR Design for Invoice PDF

### QR Payload

The QR code embedded in the invoice PDF should contain **only** the minimum data needed for verification:

```json
{
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "invoice_number": "INV-2026-000042",
  "verification_hash": "abc123def456...",
  "issued_at": "2026-07-28T10:00:00+00:00",
  "verification_url": "https://shop.example.com/api/v1/general/invoices/verify/550e8400-e29b-41d4-a716-446655440000"
}
```

### Field Justification

| Field | Size | Reason |
|---|---|---|
| `uuid` | 36 chars | Primary lookup key for verification endpoint |
| `invoice_number` | ~18 chars | Human-readable identifier displayed after scan |
| `verification_hash` | 16 chars (truncated) | Truncated to first 16 chars to reduce QR density; full hash on server |
| `issued_at` | 25 chars | Timestamp of issuance for display |
| `verification_url` | ~80 chars | URL to open for verification (QR scanners auto-open URLs) |

### Truncation Rationale

The full `verification_hash` is 64 hex characters. Including it in the QR code would increase QR density significantly. **Only the first 16 characters should be embedded in the QR.** The server holds the full 64-character hash and can match against the truncated version for a quick pre-check, then redirect to the full verification page.

If the first 16 hex chars match, it's cryptographically sufficient for display purposes — the full verification is done on the server side anyway.

### Recommended QR Generation

Using the existing `chillerlan/php-qrcode` library already in the codebase:

```php
// Proposed implementation in GenerateInvoicePdfJob (when real PDF is built)
$qrPayload = json_encode([
    'uuid' => $invoice->uuid,
    'invoice_number' => $invoice->invoice_number,
    'verification_hash' => substr($invoice->verification_hash, 0, 16),
    'issued_at' => $invoice->generated_at->toIso8601String(),
    'verification_url' => url('/api/v1/general/invoices/verify/' . $invoice->uuid),
]);

// Generate QR as SVG and embed in PDF
$qrService = app(CashierQrService::class);  // Reuse or create InvoiceQrService
$qrSvg = $qrService->generateSvgFromPayload($qrPayload);
```

### QR Placement on PDF

The QR should appear prominently on the invoice PDF:

```
┌─────────────────────────────────────────┐
│                                         │
│          INVOICE                         │
│          INV-2026-000042                 │
│                                         │
│  ┌─────────────────────────────────┐    │
│  │  Item        Qty   Price  Total │    │
│  │  Product A     2   50.00 100.00 │    │
│  │  Product B     1   30.00  30.00 │    │
│  └─────────────────────────────────┘    │
│                                         │
│  Subtotal: 130.00                       │
│  Total:   130.00                        │
│                                         │
│  ┌─────────────────────────┐            │
│  │     ██ ████ ██ ████    │            │
│  │     ██ QR CODE ████    │            │
│  │     ██ ████ ██ ████    │            │
│  └─────────────────────────┘            │
│  Scan to verify this invoice            │
│                                         │
│  Issued: 2026-07-28                     │
│  Verification URL: shop.example.com/... │
│                                         │
└─────────────────────────────────────────┘
```

---

## Security Architecture

```
                    ┌──────────────────┐
                    │   Invoice Data   │
                    │  (snapshot JSON) │
                    └────────┬─────────┘
                             │
                             ▼
              ┌──────────────────────────────┐
              │ SnapshotIntegrityService     │
              │  sortRecursive → json_encode │
              │  → SHA-256                   │
              └──────────────┬───────────────┘
                             │
                             ▼
                    ┌──────────────────┐
                    │  snapshot_hash   │  (64 hex chars)
                    └────────┬─────────┘
                             │
                             ▼
              ┌──────────────────────────────┐
              │ computeVerificationHash()    │
              │  SHA-256(snapshot_hash . key)│
              └──────────────┬───────────────┘
                             │
                             ▼
                    ┌──────────────────┐
                    │ verification_hash│  (64 hex chars)
                    │  stored in DB    │
                    └────────┬─────────┘
                             │
               ┌─────────────┴─────────────┐
               │                           │
               ▼                           ▼
        ┌─────────────────┐     ┌──────────────────────┐
        │ QR Code on PDF  │     │ API Response         │
        │ (truncated to   │     │ (full verification   │
        │  first 16 chars)│     │  hash in qr_content) │
        └─────────────────┘     └──────────────────────┘
               │                           │
               │                           │
               └──────────┬────────────────┘
                          │
                          ▼
              ┌──────────────────────────────┐
              │ Verification Endpoint        │
              │ /api/v1/.../verify/{uuid}    │
              │                              │
              │ Recomputes expected hash,    │
              │ compares with stored hash    │
              │ via hash_equals()            │
              └──────────────────────────────┘
```

---

## Verification Flow Diagram

```
User scans QR / clicks verification URL
              │
              ▼
    GET /api/v1/general/invoices/verify/{uuid}
    [throttle: 60/min, no auth required]
              │
              ▼
    InvoiceService::verifyInvoice($uuid)
              │
              ├─ Find invoice by UUID
              │   └─ Not found → return null → 404
              │
              ├─ Compute expected hash:
              │   SHA-256(snapshot_hash . app_key)
              │
              ├─ Compare with stored verification_hash
              │   using hash_equals()
              │
              ├─ Match?
              │   ├─ YES → authentic = true, tampered = false
              │   └─ NO  → authentic = false, tampered = true
              │
              ▼
    InvoiceController::verify()
              │
              ├─ null → 404 NOT FOUND
              ├─ tampered → 409 CONFLICT
              └─ authentic →
                    ├─ Increment verify_count
                    ├─ Set last_verified_at
                    ├─ Set verified_at (first time only)
                    ├─ timelineService->recordVerified()
                    └─ 200 OK with invoice + order + qr_content
```

---

## Implementation Roadmap

### Step 1: Enable Real PDF Generation (HIGH priority)

The `GenerateInvoicePdfJob` is a placeholder. Before QR can be embedded, real PDF generation must be implemented.

**Approach**: Use `barryvdh/laravel-dompdf` or `mpdf/mpdf` to:
- Render an invoice template with order data from the snapshot
- Generate PDF and store to `storage/invoices/{uuid}.pdf`
- Set `pdf_path` and `pdf_checksum` on the invoice record

### Step 2: Create InvoiceQrService (MEDIUM priority)

Create `app/Services/Invoice/InvoiceQrService.php` that reuses `chillerlan/php-qrcode`:

```php
class InvoiceQrService
{
    public function generateForInvoice(Invoice $invoice): string
    {
        $payload = json_encode([
            'uuid' => $invoice->uuid,
            'invoice_number' => $invoice->invoice_number,
            'verification_hash' => substr($invoice->verification_hash, 0, 16),
            'issued_at' => $invoice->generated_at->toIso8601String(),
            'verification_url' => url('/api/v1/general/invoices/verify/' . $invoice->uuid),
        ]);

        // Generate QR SVG with ECC level M (medium) for document embedding
        return $this->generateQrSvg($payload, EccLevel::M);
    }
}
```

### Step 3: Embed QR in PDF Template (MEDIUM priority)

In the PDF generation template, embed the QR SVG:
- Position: Bottom-right corner of the invoice
- Size: Approximately 1.5 inches (38mm) square
- Label: "Scan to verify this invoice"
- Alt text: Full verification URL below QR for non-scannable copies

### Step 4: Verification Counter & Audit (DONE — already implemented)

The verification endpoint already:
- Increments `verify_count`
- Sets `last_verified_at`
- Sets `verified_at` on first verification
- Records timeline event via `recordVerified()`

### Step 5: Public Verification Page (LOW priority)

Currently the verification endpoint returns JSON. Consider adding a web page:
- `GET /verify/{uuid}` returns an HTML page
- Scans the QR → opens this page
- Page shows: authentic/tampered status, invoice summary, order details
- No authentication required (public verification)

---

## Edge Cases & Threat Model

### Threat 1: Hash Collision Attack

**Risk**: Attacker finds two different invoice snapshots with the same SHA-256 hash.

**Mitigation**: SHA-256 collision resistance is 2^128 (birthday attack). Computational infeasibility.

### Threat 2: app.key Compromise

**Risk**: If `app.key` is leaked, attacker can forge valid verification hashes for any invoice.

**Severity**: **CRITICAL**

**Mitigation**:
- Store `app.key` in environment variable only
- Use Laravel's encryption (which already uses `app.key`)
- Rotate key only with a plan to recompute verification hashes
- Audit log any access to `.env` file
- Consider a separate `INVOICE_VERIFICATION_KEY` environment variable for invoice verification specifically, so that the encryption key and verification key can be rotated independently

### Threat 3: Verification Hash Truncation (QR)

**Risk**: Truncating `verification_hash` to 16 characters in the QR creates a 2^64 search space (birthday bound 2^32). An attacker generating many invoices could find a truncated collision.

**Severity**: **LOW** — The full 64-char hash is still stored and verified server-side. Truncation in the QR is only for QR density; the server always checks the full hash.

### Threat 4: Replay Attack

**Risk**: An attacker copies a valid QR from one invoice to a fake invoice PDF.

**Mitigation**: The `uuid` binds the QR to a specific invoice. When scanned, the server looks up the UUID and verifies the hash matches. A QR from invoice A pasted onto invoice B's PDF will cause a UUID mismatch (the server returns invoice A's data, not B's).

### Threat 5: Verification Endpoint DDoS

**Risk**: Public endpoint with no auth can be abused.

**Mitigation**: Rate limit of 60 requests per minute per IP via `throttle:60,1` middleware. Consider:
- Adding CAPTCHA after threshold
- Cache success/failure per UUID for short TTL (duplicate scans within 60s skip recomputation)
- Monitoring for abnormal verify_count spikes per invoice

### Threat 6: Timing Attack on Hash Comparison

**Risk**: Attacker measures response time to brute-force the verification hash.

**Mitigation**: `hash_equals()` runs in constant time. The comparison time does not leak information about which characters match.

### Threat 7: Invoice Data Tampering (Direct DB Access)

**Risk**: Attacker with DB access modifies the `data` (snapshot) column and the `snapshot_hash` and `verification_hash` together.

**Severity**: **CRITICAL** — If an attacker has DB write access, they can modify any field and recompute both hashes (since they'd have access to `app.key` from the app config or env file).

**Mitigation**:
- Tight DB access controls
- Encrypt sensitive columns at rest
- Database audit logging
- Consider signing verification hashes with a hardware security module (HSM) or key management service

### Edge Case: QR on Correction Invoice

When an invoice is corrected (new invoice with `correction_to_id`), the correction invoice gets its own `uuid`, `verification_hash`, and QR. The original invoice's QR (still valid, but status is `corrected`) should display a warning: "This invoice has been superseded by a correction."

### Edge Case: QR on Cancelled Invoice

Cancelled invoices should have a QR that verifies authenticity but warns: "This invoice has been cancelled."

### Edge Case: No PDF Generated Yet

If `GenerateInvoicePdfJob` has not run (status `generated`), there is no PDF to embed QR on. The QR content is still available via the API response (`qr_content` field) for frontend display.

---

## Key Files Reference

| File | Purpose |
|---|---|
| `app/Services/Gateway/CashierQrService.php` | Current QR generation (cashier only) |
| `app/Services/Invoice/InvoiceService.php` | Verification hash computation |
| `app/Http/Controllers/Api/InvoiceController.php` | Verify endpoint |
| `app/Http/Resources/Invoice/InvoiceResource.php` | QR content in API response |
| `routes/api.php` | Route definition with throttle middleware |
| `app/Services/Invoice/SnapshotIntegrityService.php` | Snapshot hash computation |
