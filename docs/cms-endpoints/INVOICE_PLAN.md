# Invoice System — Implementation Plan

> This document is a **future implementation plan only**.
> No database changes or code implementation should be done based on this document alone.

---

## Table of Contents

1. [Current State](#current-state)
2. [Requirements](#requirements)
3. [Approach Comparison](#approach-comparison)
4. [Recommended Approach](#recommended-approach)
5. [Future Implementation](#future-implementation)

---

## Current State

The project already has a basic invoice generation system:

- **PDF generation:** `Marvel\Http\Controllers\OrderController::downloadInvoice()` generates an invoice PDF using `dompdf`.
- **View:** `resources/views/pdf/order-invoice.blade.php` renders the invoice.
- **Download flow:** A signed URL is generated via `downloadInvoiceUrl()` with a one-time `DownloadToken`. The token is consumed when the PDF is downloaded.

However, there is no:
- Dedicated `invoices` database table.
- Invoice number sequencing.
- Invoice storage/persistence.
- Email delivery of invoices.
- Customer invoice dashboard.

---

## Requirements

An invoice should contain:

### Customer Information
- Customer name
- Phone number
- Email address
- Billing address (full snapshot)

### Order Information
- Order number (e.g. `ORD-00000001`)
- Order date
- Order status
- Tracking number

### Products
- Product name (snapshotted from `order_products`)
- Quantity ordered
- Unit price
- Line total
- Discount applied
- Promotion applied

### Payment Information
- Payment method
- Transaction ID / Invoice ID
- Payment status
- Amount paid

---

## Approach Comparison

### Option 1: Dedicated `invoices` Table

```php
Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();
    $table->string('invoice_number')->unique();       // e.g. INV-2026-000001
    $table->string('status')->default('pending');       // pending, generated, sent, paid, cancelled
    $table->json('invoice_data');                       // Full snapshot of invoice data
    $table->string('pdf_path')->nullable();             // Path to stored PDF file
    $table->timestamp('generated_at')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamp('paid_at')->nullable();
    $table->timestamps();
});
```

**Pros:**
- Dedicated invoice number sequence independent of order IDs.
- Stores a full snapshot, so invoice data never changes even if order data is modified.
- Can track invoice lifecycle separately from order lifecycle.
- Supports future needs: partial invoicing, credit notes, recurring invoices.
- PDF can be generated once and stored permanently.

**Cons:**
- Requires a new table and model.
- Data duplication with `orders` and `order_products` tables.
- Requires a generation step after order completion.
- More complex queries (join to orders + order_products).

### Option 2: Generate Invoice Directly from Orders

Use the existing data in `orders` + `order_products` + `transactions` tables to generate invoices on-the-fly.

```php
class InvoiceGenerator
{
    public function generate(Order $order): array
    {
        return [
            'invoice_number' => 'INV-' . $order->order_number,
            'customer' => [
                'name' => $order->name,
                'phone' => $order->user_phone,
                'email' => $order->user_email,
                'address' => $order->address,
            ],
            'order' => [
                'number' => $order->order_number,
                'date' => $order->created_at,
                'status' => $order->status,
            ],
            'items' => $order->orderItems->map(fn ($item) => [
                'product_name' => $item->product_name,
                'quantity' => $item->product_quantity,
                'unit_price' => $item->product_price,
                'total' => $item->product_total_price,
                'discount' => $item->product_discount_price,
            ]),
            'totals' => [
                'subtotal' => $order->price,
                'discount' => $order->coupon_discount + $order->promotion_discount,
                'total' => $order->total_price,
            ],
            'payment' => [
                'method' => $order->transactions->first()?->payment_method,
                'transaction_id' => $order->transactions->first()?->invoice_id,
                'status' => $order->payment_status,
            ],
        ];
    }
}
```

**Pros:**
- No new table — zero data duplication.
- Always up-to-date (order data is already snapshotted).
- Simpler architecture, easier to maintain.
- No sync issues between order and invoice data.
- Faster to implement.

**Cons:**
- Invoice number is derived from order data, not a standalone sequence.
- Cannot independently track invoice lifecycle (sent, viewed, paid).
- If order data is modified (unlikely since it is snapshotted), invoice changes retroactively.
- Less flexible for future features like partial invoicing.

---

## Recommended Approach

**Recommendation: Option 2 — Generate Invoice Directly from Orders**

### Justification

1. **The order data is already snapshotted.** The `orders` and `order_products` tables store immutable copies of all customer information, product details, and prices. There is no risk of data changing after order creation.

2. **Simpler architecture.** A dedicated `invoices` table would duplicate data that is already stored and protected in the order tables. This violates DRY and increases maintenance overhead.

3. **Faster to implement.** Option 2 requires only a service class and a PDF view. No migration, no model, no sync logic.

4. **Sufficient for current needs.** The existing `downloadInvoice()` method already generates PDFs from order data. The improvement is to formalize this into a clean `InvoiceService` class.

### When Option 1 Would Be Better

Option 1 becomes justified when:
- You need sequential, gap-free invoice numbers (legal/accounting requirement in some jurisdictions).
- You need to send invoices before an order is fully completed (deposits, partial payments).
- You need credit notes, debit notes, or proforma invoices.
- You need to track when invoices were sent, viewed, and paid independently of order status.

---

## Future Implementation

### Step 1: Create InvoiceService

```php
namespace App\Services\General;

class InvoiceService
{
    public function generateInvoiceData(Order $order): array { ... }
    public function generatePdf(Order $order): string { ... }
    public function streamPdf(Order $order): StreamedResponse { ... }
    public function downloadPdf(Order $order): BinaryFileResponse { ... }
}
```

### Step 2: PDF Generation

```php
use Barryvdh\DomPDF\Facade\Pdf;

public function generatePdf(Order $order): string
{
    $data = $this->generateInvoiceData($order);
    $pdf = Pdf::loadView('pdf.invoice', $data);

    $filename = "invoice-{$order->order_number}.pdf";
    $path = storage_path("app/invoices/{$filename}");

    $pdf->save($path);

    return $path;
}
```

**View:** Create a dedicated `resources/views/pdf/invoice.blade.php` with professional design (logo, company info, line items, totals, payment terms).

### Step 3: Email Invoice

```php
use App\Mail\InvoiceMail;

// After order completion:
Mail::to($order->user_email)->send(new InvoiceMail($order));

// InvoiceMail.php
class InvoiceMail extends Mailable
{
    public function build()
    {
        return $this->subject("Invoice for Order {$order->order_number}")
                    ->attach($this->invoiceService->generatePdf($this->order), [
                        'as' => "invoice-{$this->order->order_number}.pdf",
                        'mime' => 'application/pdf',
                    ])
                    ->view('emails.invoice');
    }
}
```

### Step 4: Download Invoice Endpoint

```php
// Route
Route::get('orders/{order}/invoice', [OrderController::class, 'downloadInvoice'])
    ->middleware('auth:sanctum');

// Controller
public function downloadInvoice(Order $order, InvoiceService $invoiceService)
{
    $this->authorize('view', $order);

    return $invoiceService->downloadPdf($order);
}
```

### Step 5: Admin Invoice Management (Optional)

If Option 1 is chosen later:
- Admin panel with invoice list.
- Search/filter by invoice number, customer, date range.
- Resend invoice by email.
- Mark as paid/unpaid.
- Generate credit notes.
- Export invoices as CSV/Excel for accounting.
