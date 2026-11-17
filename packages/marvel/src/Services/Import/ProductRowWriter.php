<?php

namespace Marvel\Services\Import;

use App\Support\ProductTypeSchema;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Enums\ProductType;
use Marvel\Services\Pricing\ProductPricingService;
use Throwable;

/**
 * Writes one products-sheet row: build, validate, persist, price and verify
 * the stored product_type. Failures are reported as product-row errors; a
 * successful write records one success on the run outcome.
 */
final class ProductRowWriter
{
    /**
     * Product types already verified against the database (one probe per value,
     * not one per row).
     *
     * @var array<string, true>
     */
    private array $verifiedProductTypes = [];

    /** @var array<int, int> */
    private array $createdProductIds = [];

    /** @var array<int, int> */
    private array $touchedProductIds = [];

    public function __construct(
        private readonly ProductPricingService $pricingService,
        private readonly ImportReport $report,
        private readonly RunOutcome $outcome,
    ) {
    }

    /**
     * @return array<int, int>
     */
    public function createdProductIds(): array
    {
        return $this->createdProductIds;
    }

    /**
     * Every product row this run wrote (created or upserted) — the set the
     * final type normalisation is allowed to touch.
     *
     * @return array<int, int>
     */
    public function touchedProductIds(): array
    {
        return $this->touchedProductIds;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function write(array $row, int $rowIndex): void
    {
        try {
            DB::beginTransaction();

            $productData = $this->buildProductData($row);
            $product = $this->saveProduct($row, $productData);

            $this->applyPricing($product);

            // The ENUM can silently store '' when strict mode is off; verify the
            // value that actually reached the database instead of trusting fill().
            $this->assertPersistedProductType($product, $productData['product_type'] ?? null);

            DB::commit();

            $this->recordInStockInconsistency($product, $productData);
            $this->outcome->recordSuccess();
        } catch (Exception $e) {
            DB::rollBack();
            $this->report->addProductRowError(
                $rowIndex,
                (string) ($row['sku'] ?? 'N/A'),
                $e->getMessage()
            );
        }
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function saveProduct(array $row, array &$productData): Product
    {
        $sku = $row['sku'] ?? null;
        $product = empty($sku) ? null : Product::where('sku', $sku)->first();

        $productData['sku'] = empty($sku) ? 'PRD-' . Str::uuid() : $sku;

        if ($product === null) {
            $productData['slug'] = $this->generateSlug($row);
            $product = new Product($productData);
            $product->saveQuietly();
            $this->createdProductIds[] = $product->id;
        } else {
            $productData['slug'] = $product->slug;

            // A product that owns variant rows is variable by domain
            // definition (ProductTypeSchema); the sheet's product_type cell
            // must not clobber that on upsert. The correction propagates to
            // the caller's expected value so the persisted-ENUM verification
            // checks what was actually written. Variants the sheet adds later
            // in the same run re-assert it in processVariantRow.
            if ($this->productOwnsVariants($product->getKey())) {
                $productData['product_type'] = ProductType::VARIABLE;
            }

            if ($this->itemTypeIsImmutable($product, $productData)) {
                throw new \InvalidArgumentException(__('message.ERROR.ITEM_TYPE_IMMUTABLE_ORDERED'));
            }

            $product->fill($productData)->saveQuietly();
        }

        $this->touchedProductIds[] = $product->id;

        return $product;
    }

    private function productOwnsVariants(int $productId): bool
    {
        return DB::table('product_variants')->where('product_id', $productId)->exists();
    }

    /**
     * Final type normalisation for this run's touched products only:
     * owns variant rows ⇒ variable, otherwise simple. Runs once after the
     * variants sheet is settled (finalizeVariants), so a `variable` cell on
     * a product that never gains variants cannot survive as broken data, and
     * a variant-gaining row cannot end as simple.
     */
    public function normalizeProductTypes(): void
    {
        $touched = array_values(array_unique(array_map('intval', $this->touchedProductIds)));

        if ($touched === []) {
            return;
        }

        DB::table('products')
            ->whereIn('id', $touched)
            ->whereExists(static function ($query): void {
                $query->select(DB::raw(1))
                    ->from('product_variants')
                    ->whereColumn('product_variants.product_id', 'products.id');
            })
            ->update(['product_type' => ProductType::VARIABLE]);

        DB::table('products')
            ->whereIn('id', $touched)
            ->whereNotExists(static function ($query): void {
                $query->select(DB::raw(1))
                    ->from('product_variants')
                    ->whereColumn('product_variants.product_id', 'products.id');
            })
            ->update(['product_type' => ProductType::SIMPLE]);
    }

    /**
     * D5 — item_type immutability for products that already have orders.
     *
     * @param  array<string, mixed>  $productData
     */
    private function itemTypeIsImmutable(Product $product, array $productData): bool
    {
        if (!isset($productData['item_type']) || $productData['item_type'] === $product->item_type) {
            return false;
        }

        return OrderProduct::where('product_id', $product->id)->exists();
    }

    private function applyPricing(Product $product): void
    {
        $pricing = $this->pricingService->calculateProductPricingFromData(
            $product->toArray(),
            $product->getActiveFlashSale()
        );

        $product->fill([
            'price_after_discount' => $pricing['price_after_discount'] ?? null,
            'price_after_flash_sale' => $pricing['price_after_flash_sale'] ?? null,
        ])->saveQuietly();
    }

    /**
     * Verify the stored product_type for an already-persisted product
     * (the variant path sets VARIABLE after writing a variant).
     */
    public function verifyPersistedProductType(Product $product, ?string $expected): void
    {
        $this->assertPersistedProductType($product, $expected);
    }

    /**
     * Verify the ENUM write actually landed as requested.
     *
     * MySQL with strict mode off silently turns a value the ENUM cannot
     * represent into '', which then looks like "no product type" everywhere
     * downstream. Fail the row instead of leaving corrupt catalog data behind.
     */
    private function assertPersistedProductType(Product $product, ?string $expected): void
    {
        $cacheKey = $expected ?? '__default__';

        if (isset($this->verifiedProductTypes[$cacheKey])) {
            return;
        }

        // Read the physical column, bypassing model scopes: the guard exists to
        // prove what the database stored, not what the current channel can see.
        $stored = DB::table('products')->where('id', $product->getKey())->value('product_type');
        $columnContract = (new ProductTypeSchema())->canonicalEnum();

        if ($expected !== null && $stored !== $expected) {
            throw new \RuntimeException(__('message.IMPORT.PRODUCT.PRODUCT_TYPE_NOT_STORED', [
                'expected_value' => $expected,
                'stored_value' => (string) $stored,
                'column_type' => $columnContract,
            ]));
        }

        if ($expected === null && !in_array($stored, ProductType::getValues(), true)) {
            throw new \RuntimeException(__('message.IMPORT.PRODUCT.PRODUCT_TYPE_INVALID_STORED', [
                'stored_value' => (string) $stored,
                'column_type' => $columnContract,
            ]));
        }

        $this->verifiedProductTypes[$cacheKey] = true;
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function recordInStockInconsistency(Product $product, array $productData): void
    {
        $quantity = $productData['quantity'] ?? (int) $product->quantity;

        if (($productData['in_stock'] ?? null) === false && (int) $quantity > 0) {
            $this->report->noteInStockInconsistency();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function buildProductData(array $row): array
    {
        $data = [];

        $name = [];
        if (!empty($row['name_en'])) {
            $name['en'] = $row['name_en'];
        }
        if (!empty($row['name_ar'])) {
            $name['ar'] = $row['name_ar'];
        }
        if (!empty($name)) {
            $data['name'] = $name;
        }

        $description = [];
        if (!empty($row['description_en'])) {
            $description['en'] = $row['description_en'];
        }
        if (!empty($row['description_ar'])) {
            $description['ar'] = $row['description_ar'];
        }
        if (!empty($description)) {
            $data['description'] = $description;
        }

        if (isset($row['price'])) {
            if (!is_numeric($row['price']) || (float) $row['price'] < 0) {
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_PRICE', [
                    'given_value' => $row['price'],
                ]));
            }
            $data['price'] = (float) $row['price'];
        }

        // Blank cells mean "not provided" (the column default applies); a
        // provided value is normalized so case/whitespace cannot produce an
        // empty ENUM value on case-sensitive engines.
        if (array_key_exists('product_type', $row) && $row['product_type'] !== null) {
            $productType = strtolower(trim((string) $row['product_type']));

            if ($productType !== '') {
                if (!in_array($productType, ProductType::getValues(), true)) {
                    throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_PRODUCT_TYPE', [
                        'given_value' => $row['product_type'],
                        'allowed_values' => implode(', ', ProductType::getValues()),
                    ]));
                }
                $data['product_type'] = $productType;
            }
        }

        if (isset($row['item_type'])) {
            $itemType = strtoupper(trim((string) $row['item_type']));

            if (!in_array($itemType, \Marvel\Enums\ItemType::getValues(), true)) {
                // Invalid values are rejected — never silently defaulted.
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_ITEM_TYPE', [
                    'given_value' => $row['item_type'],
                    'allowed_values' => implode(', ', \Marvel\Enums\ItemType::getValues()),
                ]));
            }

            $data['item_type'] = $itemType;
        }

        if (isset($row['quantity'])) {
            if (!is_numeric($row['quantity']) || (int) $row['quantity'] < 0) {
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_QUANTITY', [
                    'given_value' => $row['quantity'],
                ]));
            }
            $data['stock_quantity'] = (int) $row['quantity'];
            $data['quantity'] = (int) $row['quantity'];
        }

        if (array_key_exists('tax_enabled', $row)) {
            $data['tax_enabled'] = $this->parseBoolean($row['tax_enabled']);
        }
        if (array_key_exists('tax_rate', $row)) {
            if ($row['tax_rate'] === '' || $row['tax_rate'] === null) {
                $data['tax_rate'] = null;
            } else {
                if (!is_numeric($row['tax_rate']) || (float)$row['tax_rate'] < 0 || (float)$row['tax_rate'] > 100) {
                    throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_TAX_RATE', [
                        'given_value' => $row['tax_rate'],
                    ]));
                }
                $data['tax_rate'] = (float) $row['tax_rate'];
            }
        }

        if (isset($row['status'])) {
            $data['status'] = $this->normalizeProductStatus($row['status']);
        }

        if (isset($row['in_stock'])) {
            $data['in_stock'] = $this->parseBoolean($row['in_stock']);
        }

        if (isset($row['has_discount'])) {
            $data['has_discount'] = $this->parseBoolean($row['has_discount']);
        }

        if (isset($row['discount_type'])) {
            if (!in_array($row['discount_type'], \Marvel\Enums\DiscountType::getValues(), true)) {
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_DISCOUNT_TYPE', [
                    'given_value' => $row['discount_type'],
                    'allowed_values' => implode(', ', \Marvel\Enums\DiscountType::getValues()),
                ]));
            }
            $data['discount_type'] = $row['discount_type'];
        }

        if (isset($row['discount_amount'])) {
            if (!is_numeric($row['discount_amount']) || (float) $row['discount_amount'] < 0) {
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_DISCOUNT_AMOUNT', [
                    'given_value' => $row['discount_amount'],
                ]));
            }
            $data['discount_amount'] = (float) $row['discount_amount'];
        }

        if (!empty($row['start_date'])) {
            $data['start_date'] = Carbon::parse($row['start_date'])->format('Y-m-d');
        }

        if (!empty($row['end_date'])) {
            $data['end_date'] = Carbon::parse($row['end_date'])->format('Y-m-d');
        }

        $dimensionFields = ['height', 'width', 'length', 'weight'];
        foreach ($dimensionFields as $field) {
            if (isset($row[$field]) && $row[$field] !== '') {
                // Dimensions are measured fields: reject non-numeric and
                // negative cells exactly like price/quantity instead of
                // persisting the raw string.
                $data[$field] = (string) $this->validateNumeric($row[$field], $field, allowFloat: true);
            }
        }

        if (isset($row['pieces'])) {
            if (!is_numeric($row['pieces']) || (int) $row['pieces'] < 0) {
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_PIECES', [
                    'given_value' => $row['pieces'],
                ]));
            }
            $data['pieces'] = (int) $row['pieces'];
        }

        if (isset($row['has_flash_sale'])) {
            $data['has_flash_sale'] = $this->parseBoolean($row['has_flash_sale']);
        }

        return $data;
    }

    public function validateNumeric($value, string $field, bool $allowFloat = false, bool $isInteger = false)
    {
        if ($value === null || $value === '') {
            return $isInteger ? 0 : 0.0;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_NUMERIC_FIELD', ['field_name' => $field]));
        }

        $numeric = $isInteger ? (int) $value : (float) $value;

        if ($numeric < 0) {
            throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.NEGATIVE_VALUE_NOT_ALLOWED', ['field_name' => $field]));
        }

        return $numeric;
    }

    protected function generateSlug(array $row): string
    {
        $baseSlug = Str::slug($row['name_en'] ?? $row['sku'] ?? 'product-' . Str::random(6));
        $slug = $baseSlug;
        $count = 1;

        while (Product::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $count++;
        }

        return $slug;
    }

    /**
     * Normalize Product status from import file to canonical integer 0 or 1.
     * Accepts only "0" or "1" (string or numeric).
     * Rejects all other values including true/false/publish/unpublish.
     */
    protected function normalizeProductStatus($value): int
    {
        if (is_numeric($value)) {
            $status = (int) $value;

            if ($status === 0 || $status === 1) {
                return $status;
            }
        } elseif (is_string($value) && in_array(trim($value), ['0', '1'], true)) {
            return (int) trim($value);
        }

        throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_STATUS', [
            'given_value' => is_scalar($value) ? $value : gettype($value),
        ]));
    }

    public function parseBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $numeric = (float) $value;
            if ($numeric === 1.0) {
                return true;
            }
            if ($numeric === 0.0) {
                return false;
            }
            throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_BOOLEAN', [
                'given_value' => $value,
            ]));
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', 'yes', 'y', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', 'no', 'n', 'off', ''], true)) {
                return false;
            }
            // Unknown text used to be coerced to false, which silently inverted
            // flags such as in_stock. Reject it so the row is reported instead.
            throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_BOOLEAN', [
                'given_value' => $value,
            ]));
        }
        return false;
    }
}
