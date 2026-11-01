<?php

namespace App\Services\Product;

use App\Models\ProductBarcode;
use Illuminate\Http\Request;
use Marvel\Database\Models\Product;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Single source of truth for product piece/carton barcode persistence.
 *
 * Semantics (final approved design — one row per product):
 * - product_barcodes has exactly zero-or-one row per product; the row is
 *   created on first use and never deleted by partial updates.
 * - Fields are independently nullable: NULL = unknown / not provided.
 * - Request updates use presence semantics: a key present in the payload
 *   is applied (null clears it); an omitted key preserves the stored value.
 * - Barcode VALUES are unique across BOTH columns: a piece barcode must
 *   not equal another product's carton barcode and vice versa; the same
 *   product may not use identical piece/carton values. Checks always
 *   exclude the current product on updates.
 *
 * Uniqueness is enforced here and by App\Rules\UniqueProductBarcode for
 * FormRequest-level errors; the per-column unique indexes backstop
 * same-column duplicates at the database layer.
 */
class ProductBarcodeService
{
    /**
     * The three writable barcode attributes (field names shared with the
     * API contract, resources, and the Excel importer).
     */
    public const FIELDS = ['piece_barcode', 'carton_barcode', 'pieces_per_carton'];

    /**
     * Apply the barcode fields present in the request body (null = explicit
     * clear, omitted key = preserve). No-op when the request carries none of
     * the fields, so creating/updating products without barcode info never
     * touches the barcode table.
     */
    public function syncFromRequest(Product $product, Request $request): void
    {
        $present = $request->only(self::FIELDS);

        $this->applyValues($product, $present);
    }

    /**
     * Merge provided values onto the product's barcode row (created on
     * first use) and persist for real.
     *
     * @param array<string, string|int|null> $present key=>value map of
     *        explicitly provided fields only (values may be null to clear)
     *
     * @throws HttpException 422 with a client-facing message when the
     *         resulting state violates barcode uniqueness.
     */
    public function applyValues(Product $product, array $present): void
    {
        if ($present === []) {
            return;
        }

        $row = ProductBarcode::firstOrCreate(['product_id' => $product->id]);

        // Final state = stored row + explicit overrides; absent keys keep
        // their stored values, so a piece/carton swap-update that leaves the
        // other column untouched is still checked against the stored value.
        $final = array_merge($row->only(['piece_barcode', 'carton_barcode']), $present);

        $this->assertValid($product->id, $final);

        $row->fill($present)->save();
    }

    /**
     * Integrity checks for a candidate final barcode state. Used both by
     * updates (product CRUD transactions → HttpException 422 rethrew as-is)
     * and by the Excel importer (row errors only need the message).
     *
     * @param int|null $ignoreProductId product whose row may keep its own
     *        values (update path); null for create validation.
     * @param array{piece_barcode?: string|null, carton_barcode?: string|null, pieces_per_carton?: int|null} $final
     *
     * @throws HttpException 422 when a barcode value is taken by another
     *         product or equals the sibling barcode of the same product.
     */
    public function assertValid(?int $ignoreProductId, array $final): void
    {
        $piece = $final['piece_barcode'] ?? null;
        $carton = $final['carton_barcode'] ?? null;

        if ($piece !== null && $piece === $carton) {
            throw new HttpException(422, 'The piece barcode and the carton barcode must be different.');
        }

        foreach (['piece_barcode' => $piece, 'carton_barcode' => $carton] as $column => $value) {
            if ($value === null) {
                continue;
            }

            $taken = ProductBarcode::query()
                ->where(function ($query) use ($value) {
                    $query->where('piece_barcode', $value)->orWhere('carton_barcode', $value);
                })
                ->when($ignoreProductId !== null, fn ($query) => $query->where('product_id', '!=', $ignoreProductId))
                ->exists();

            if ($taken) {
                throw new HttpException(422, "The barcode {$value} is already assigned to another product.");
            }
        }
    }
}
