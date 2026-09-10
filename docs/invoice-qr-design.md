# Invoice QR Design — Zero-Trust Production Audit

**Date**: 2026-07-27  
**Scope**: QR payload design for invoice verification, security analysis, integration with existing QR code generation  
**Trust Level**: ZERO — every claim verified against source code

---

## Table of Contents

1. [Existing QR Implementation](#1-existing-qr-implementation)
2. [Invoice QR Design](#2-invoice-qr-design)
3. [Verification Endpoint](#3-verification-endpoint)
4. [Security Analysis](#4-security-analysis)
5. [Design Recommendations](#5-design-recommendations)

---

## 1. Existing QR Implementation

### 1.1 Cashier QR Code

Already implemented in `CashierQrService`:

```php
$payload = json_encode([
    'transaction' => $transaction->uuid,
]);

// QR encoded as SVG, returned as base64 data URI
return 'data:image/svg+xml;base64,' . base64_encode($svg);
```

**Current payload**: `{"transaction": "uuid-string-here"}`

**Usage**: Generated during checkout for `pay_at_cashier` method. Displayed to customer. Cashier scans it to identify the transaction and mark it as paid.

**Security**: 
- Uses ECC level L (lowest — 7% error recovery)
- No verification hash
- No expiration timestamp
- No signature

**BUG-QR-001**: The cashier QR contains only a UUID. Any valid UUID in the system can be used to attempt payment. An attacker could generate a fake QR with any UUID. The `getTransactionQr` endpoint checks `auth()->id() === $transaction->user_id` — but the cashier scanning the QR is likely an admin or cashier user, not the customer. The authorization check would FAIL if a customer tries to view another customer's QR, but a cashier scanning the QR from a fake source would not be caught by this guard.

**BUG-QR-002**: No replay protection. If a transaction QR is intercepted (e.g., screenshot, shared), anyone with the QR can present it to a cashier to process payment. The cashier would scan the QR, find the transaction UUID, and mark it as paid — potentially paying someone else's order.

### 1.2 Transaction Model QR Field

The `transactions` table has a `qr_code_url` column (nullable, varchar 500). This field is currently used by the Marvel admin flow but NOT by the custom checkout cashier flow.

---

## 2. Invoice QR Design

### 2.1 Purpose

The invoice QR allows:
- **Customer**: Quick access to invoice details (scan → view invoice)
- **Verification**: Verify invoice integrity via hash
- **Audit**: Government/tax authority verification

### 2.2 Proposed Payload

```json
{
  "v": 1,
  "in": "INV-2026-000042",
  "o": 12345,
  "t": 1250.00,
  "d": "2026-07-27T14:30:00+02:00",
  "h": "a1b2c3d4e5f6..."
}
```

| Key | Field | Description |
|---|---|---|
| `v` | version | Payload version (1) |
| `in` | invoice_number | Human-readable invoice number |
| `o` | order_id | Order ID |
| `t` | total | Invoice total |
| `d` | date | ISO 8601 generation timestamp |
| `h` | hash | First 16 chars of SHA-256 of snapshot |

### 2.3 Why Not Full Snapshot in QR?

QR codes have limited capacity:
- Version 40 (177×177): max 4,296 alphanumeric chars
- A full invoice snapshot can be 2-5 KB+ (items, pricing, addresses)
- **Decision**: Store only verification fields in QR. Full data is fetched from the server.

### 2.4 Generation

```php
class InvoiceQrService
{
    public function generateForInvoice(Invoice $invoice): string
    {
        $payload = json_encode([
            'v' => 1,
            'in' => $invoice->invoice_number,
            'o' => $invoice->order_id,
            't' => (float) $invoice->total,
            'd' => $invoice->generated_at->toIso8601String(),
            'h' => substr($invoice->snapshot_hash, 0, 16),
        ]);

        // Use same QR library as CashierQrService
        return $this->qrCode->render($payload);
    }
}
```

The hash prefix (16 hex chars = 64 bits) is sufficient for verification while keeping the payload small. The probability of collision is ~2^-64 per pair of invoices.

### 2.5 Where to Display

- Invoice PDF (corner or footer)
- Customer order page (web + mobile)
- Email invoice

---

## 3. Verification Endpoint

### 3.1 Endpoint

```
GET /api/invoices/{invoice}/verify
```

### 3.2 Verification Logic

```php
public function verify(Invoice $invoice): JsonResponse
{
    $qrHash = substr($invoice->snapshot_hash, 0, 16);
    $fullHash = $invoice->snapshot_hash;

    // Recompute hash from stored data
    $computedHash = $this->integrityService->computeHash($invoice->data);

    $passed = hash_equals($fullHash, $computedHash);

    return response()->json([
        'verified' => $passed,
        'invoice_number' => $invoice->invoice_number,
        'total' => $invoice->total,
        'status' => $invoice->status,
        'qr_hash_prefix' => $qrHash,
    ]);
}
```

### 3.3 QR Scan → Verify Flow

1. Customer scans QR → reads payload
2. Client calls `GET /api/invoices/verify?number=INV-2026-000042&hash=a1b2c3d4e5f6`
3. Server finds invoice by number
4. Computes hash of stored snapshot data
5. Compares with stored `snapshot_hash`
6. Also verifies that the first 16 chars of the full hash match the QR hash prefix
7. Returns verification result

### 3.4 Public vs Authenticated

**Decision**: The verify endpoint should be PUBLIC (no auth required). The purpose of the QR is to allow anyone with the invoice to verify it. The QR already contains the invoice number and hash — these are not secrets.

---

## 4. Security Analysis

### 4.1 Threat Model

| Threat | Mitigation | Status |
|---|---|---|
| QR forgery (fake invoice number) | Verification endpoint checks hash against stored data | ✓ |
| QR replay (same QR used for different invoice) | Hash is invoice-specific, bound to snapshot data | ✓ |
| Snapshot tampering (modify DB data column) | Hash won't match recomputed hash | ✓ |
| Hash collision (two invoices with same hash prefix) | 16 hex chars = 64 bits = 2^-64 probability | ✓ |
| QR interception (someone sees your QR) | QR contains no secrets, just verification data | ✓ |
| Brute-force valid invoice numbers | Rate limit on verify endpoint | NEEDED |
| Timing attack on hash comparison | `hash_equals()` used (constant-time) | ✓ |

### 4.2 What the QR Does NOT Protect Against

- **Admin tampering**: An admin with DB access can modify invoice data AND the stored hash to match. The hash alone doesn't prevent insider attacks.
- **Mitigation**: Consider using an external signing key (HMAC with server-side secret) instead of a raw hash. The HMAC key would be stored outside the DB (in `.env`), so DB access alone isn't enough to forge valid invoices.

### 4.3 HMAC Recommendation

Replace raw SHA-256 hash with HMAC-SHA256:

```php
class SnapshotIntegrityService
{
    private string $secret;

    public function __construct()
    {
        $this->secret = config('app.invoice_signing_key');
    }

    public function computeSignature(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash_hmac('sha256', $json, $this->secret);
    }

    public function verify(array $data, string $expectedSignature): bool
    {
        return hash_equals($expectedSignature, $this->computeSignature($data));
    }
}
```

This ensures:
- DB admin cannot forge a valid invoice (they don't have the HMAC key)
- QR hash prefix is derived from HMAC, not raw data hash
- Full end-to-end integrity

---

## 5. Design Recommendations

### 5.1 Separate Invoice QR from Cashier QR

- Cashier QR (`CashierQrService`): Contains only transaction UUID. Used for payment at cashier.
- Invoice QR (`InvoiceQrService`): Contains invoice verification data. Used for customer verification.

These serve different purposes and should be separate services.

### 5.2 Fix Cashier QR Security

**Critical fix**: Add verification hash to cashier QR payload:

```php
$payload = json_encode([
    'transaction' => $transaction->uuid,
    'h' => $this->computeTransactionHash($transaction),
    'e' => now()->addHours(1)->timestamp,  // Expiration
]);
```

The cashier endpoint should verify:
1. Transaction UUID exists
2. Hash matches (prevents forged QRs)
3. Not expired (prevents replay)

### 5.3 HMAC for Invoice Signing

Replace `SnapshotIntegrityService::computeHash()` with HMAC version as described above.

### 5.4 Rate Limit Verify Endpoint

```
Route::get('invoices/{invoice}/verify', [InvoiceController::class, 'verify'])
    ->middleware('throttle:30,60');  // 30 requests per minute
```

### 5.5 Add QR to Invoice PDF

Include the invoice QR in the PDF generation template. Use `InvoiceQrService::generateForInvoice()` and embed the SVG output in the PDF.

### 5.6 QR on Order Page

Add QR display to the customer's order detail page:
```
GET /orders/{order}/invoice
  → Shows invoice JSON + QR code
```
