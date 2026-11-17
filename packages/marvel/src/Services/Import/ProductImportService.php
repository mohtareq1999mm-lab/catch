<?php

namespace Marvel\Services\Import;

use App\Traits\BroadcastsFileOperationProgress;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeProduct;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ProductType;
use Marvel\Exceptions\ImportCancelledException;
use Marvel\Services\Import\ImageHandlers\UrlImageHandler;
use Marvel\Services\Import\ProductRowWriter;
use Marvel\Services\Pricing\ProductPricingService;
use Marvel\Workbook\ProductWorkbookSchema;
use Throwable;

/**
 * Facade for the product import: one ingest method per sheet, the run outcome
 * and rollback, with the deep work behind collaborators.
 *
 * Row writing → ProductRowWriter; taxonomy linking → ImportTaxonomyLinker;
 * progress/heartbeat → ImportProgressReporter; run outcome → RunOutcome;
 * reporting → ImportReport.
 */
class ProductImportService
{
    protected ProductPricingService $pricingService;

    protected ImportReport $report;

    protected RunOutcome $outcome;

    protected ProductRowWriter $rowWriter;

    protected ImportTaxonomyLinker $taxonomyLinker;

    protected ImportProgressReporter $progress;

    protected ImageImporter $imageImporter;

    // Product is the canonical work unit (products sheet data rows).
    // Variants, barcodes, images and taxonomy are auxiliary: their failures
    // must NOT inflate product-row counters (see BE-012, 4104→4913 forensic).
    protected int $variantSuccessCount = 0;
    protected int $barcodeSuccessCount = 0;

    /** @var array<int, int> */
    protected array $keptVariantIds = [];

    protected ?int $importId = null;

    public function __construct(?int $importId = null, ?ProductPricingService $pricingService = null)
    {
        $this->report = new ImportReport();
        $this->outcome = new RunOutcome(
            fn () => $this->report->failedRowCount(),
            fn () => $this->report->all()
        );
        $this->rowWriter = new ProductRowWriter(
            $pricingService ?? app(ProductPricingService::class),
            $this->report,
            $this->outcome
        );
        $this->taxonomyLinker = new ImportTaxonomyLinker($this->report);
        $this->progress = new ImportProgressReporter($this->outcome, $importId);
        $this->importId = $importId;
    }

    public function outcome(): RunOutcome
    {
        return $this->outcome;
    }

    public function getFailedRows(): array
    {
        return $this->report->productRowErrors();
    }

    public function getSuccessCount(): int
    {
        return $this->outcome->successCount();
    }

    public function getVariantErrors(): array
    {
        return $this->report->variantErrors();
    }

    public function getVariantSuccessCount(): int
    {
        return $this->variantSuccessCount;
    }

    public function getBarcodeErrors(): array
    {
        return $this->report->barcodeErrors();
    }

    public function getBarcodeSuccessCount(): int
    {
        return $this->barcodeSuccessCount;
    }

    public function getImageErrors(): array
    {
        return $this->report->imageErrors();
    }

    public function getAllErrors(): array
    {
        return $this->report->all();
    }

    public function writeExplicitProgress(float $progress): void
    {
        $this->progress->explicit($progress);
    }

    public function setTotalRows(int $total): void
    {
        $this->outcome->setTotalRows($total);
    }

    public function finalizeProgress(): void
    {
        $this->progress->finalize();
    }

    public function getCreatedProductIds(): array
    {
        return $this->rowWriter->createdProductIds();
    }

    public function getKeptVariantIds(): array
    {
        return $this->keptVariantIds;
    }

    public function rollbackCreatedData(): void
    {
        foreach ($this->keptVariantIds as $productId => $variantIds) {
            AttributeProduct::whereIn('product_variant_id', $variantIds)->delete();
            ProductVariant::whereIn('id', $variantIds)->forceDelete();
        }

        $createdProductIds = $this->rowWriter->createdProductIds();

        if (!empty($createdProductIds)) {
            \App\Models\ProductBarcode::whereIn('product_id', $createdProductIds)->delete();
            $createdProducts = Product::whereIn('id', $createdProductIds)->get();
            foreach ($createdProducts as $product) {
                $product->categories()->detach();
                $product->brands()->detach();
                $product->flash_sales()->detach();
                $product->sliders()->detach();
                try {
                    $product->clearMediaCollection('products');
                } catch (\Throwable $e) {
                    report($e);
                }
                $product->forceDelete();
            }
        }
    }

    public function processProductRow(array $row, int $rowIndex): void
    {
        $this->rowWriter->write($row, $rowIndex);

        $this->flushProgress();
    }

    /**
     * Test seam: the row writer owns the build/validate contract.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function buildProductData(array $row): array
    {
        return $this->rowWriter->buildProductData($row);
    }

    /**
     * Test seam: the row writer owns the persisted product-type verification.
     */
    protected function assertPersistedProductType(Product $product, ?string $expected): void
    {
        $this->rowWriter->verifyPersistedProductType($product, $expected);
    }

    /**
     * Process a single row from the dedicated `product_barcodes` sheet.
     *
     * Columns: product_sku, piece_barcode, carton_barcode, pieces_per_carton.
     * Auxiliary sheet semantics (mirrors processVariantRow): errors are
     * captured by ImportReport for error-file download without inflating
     * product-row progress counters.
     */
    public function processBarcodeRow(array $row, int $rowIndex): void
    {
        $productSku = isset($row['product_sku']) ? \trim((string) $row['product_sku']) : '';
        if ($productSku === '') {
            return;
        }

        $product = Product::where('sku', $productSku)->first();
        if (!$product) {
            $this->report->addBarcodeError(
                $rowIndex,
                $productSku,
                __('message.IMPORT.PRODUCT.PRODUCT_NOT_FOUND', ['sku' => $productSku])
            );
            $this->flushAuxProgress();

            return;
        }

        try {
            DB::beginTransaction();

            $this->syncRowBarcodes($product, $row);

            DB::commit();
            $this->barcodeSuccessCount++;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->report->addBarcodeError($rowIndex, $productSku, $e->getMessage());
        }

        $this->flushAuxProgress();
    }

    /**
     * Apply the optional barcode columns of one import row to the product.
     *
     * Import semantics (isset-style, mirroring other optional columns):
     * a missing cell, NULL, or whitespace-only value means "not provided"
     * and PRESERVES the stored value; a provided value is merged over the
     * row state and validated (unknown fields never get defaults).
     *
     * @param Product $product
     * @param array<string, mixed> $row raw sheet row keyed by heading
     *
     * @throws \InvalidArgumentException|\Symfony\Component\HttpKernel\Exception\HttpException
     */
    private function syncRowBarcodes(Product $product, array $row): void
    {
        $present = [];

        foreach (\App\Services\Product\ProductBarcodeService::FIELDS as $field) {
            if (\array_key_exists($field, $row) && $row[$field] !== null && \trim((string) $row[$field]) !== '') {
                $present[$field] = $row[$field];
            }
        }

        if ($present === []) {
            return;
        }

        if (\array_key_exists('pieces_per_carton', $present)) {
            $quantity = $present['pieces_per_carton'];
            $quantityStr = \trim((string) $quantity);
            if (!\is_numeric($quantity) || \preg_match('/^\d+(\.0+)?$/', $quantityStr) !== 1 || (int) $quantity < 1) {
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_PIECES_PER_CARTON', ['given_value' => $quantity]));
            }
            $present['pieces_per_carton'] = (int) $quantity;
        }

        // Barcodes are varchar(64): Excel may hand over numeric cells, so
        // normalize the column values to strings (this is type transport,
        // not value mangling).
        foreach (['piece_barcode', 'carton_barcode'] as $field) {
            if (\array_key_exists($field, $present)) {
                $present[$field] = \trim((string) $present[$field]);
                if (\mb_strlen($present[$field]) > 64) {
                    throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.BARCODE_TOO_LONG', ['field_name' => $field]));
                }
            }
        }

        app(\App\Services\Product\ProductBarcodeService::class)->applyValues($product, $present);
    }

    public function processVariantRow(array $row, int $rowIndex): void
    {
        $productSku = $row['product_sku'] ?? null;
        if (empty($productSku)) {
            return;
        }

        $product = Product::where('sku', $productSku)->first();
        if (!$product) {
            $this->report->addVariantError(
                $rowIndex,
                (string) $productSku,
                __('message.IMPORT.PRODUCT.PRODUCT_NOT_FOUND', ['sku' => $productSku])
            );
            // Do not inflate product-row counters; still heartbeat for cancellation
            $this->flushAuxProgress();
            return;
        }

        try {
            DB::beginTransaction();

            $variant = $this->findVariantByFields($product->id, $row);

            $variantData = [
                'product_id' => $product->id,
                'sku' => $row['variant_sku'] ?? null,
                'price' => $this->rowWriter->validateNumeric($row['price'] ?? null, 'price', true),
                'sale_price' => isset($row['sale_price']) && $row['sale_price'] !== '' ? $this->rowWriter->validateNumeric($row['sale_price'], 'sale_price', true) : null,
                'stock_quantity' => $this->rowWriter->validateNumeric($row['quantity'] ?? null, 'quantity', false, true),
                'quantity' => $this->rowWriter->validateNumeric($row['quantity'] ?? null, 'quantity', false, true),
                'in_stock' => $this->rowWriter->parseBoolean($row['in_stock'] ?? true),
                // Measured fields validate like the product path: a
                // non-numeric or negative cell fails the variant row instead
                // of persisting the raw string.
                'height' => isset($row['height']) && $row['height'] !== '' ? (string) $this->rowWriter->validateNumeric($row['height'], 'height') : null,
                'width' => isset($row['width']) && $row['width'] !== '' ? (string) $this->rowWriter->validateNumeric($row['width'], 'width') : null,
                'length' => isset($row['length']) && $row['length'] !== '' ? (string) $this->rowWriter->validateNumeric($row['length'], 'length') : null,
                'weight' => isset($row['weight']) && $row['weight'] !== '' ? (string) $this->rowWriter->validateNumeric($row['weight'], 'weight') : null,
            ];

            if ($variant) {
                $variant->fill($variantData)->saveQuietly();
                $variant->attributeProducts()->delete();
            } else {
                $variant = new ProductVariant($variantData);
                $variant->saveQuietly();
            }

            $this->attachVariantAttributes($variant, $row);

            $this->keptVariantIds[$product->id][] = $variant->id;

            $product->product_type = ProductType::VARIABLE;
            $product->saveQuietly();
            $this->rowWriter->verifyPersistedProductType($product, ProductType::VARIABLE);

            DB::commit();

            $this->variantSuccessCount++;
        } catch (Exception $e) {
            DB::rollBack();
            $this->report->addVariantError($rowIndex, (string) $productSku, $e->getMessage());
        }

        $this->flushAuxProgress();
    }

    protected function findVariantByFields(int $productId, array $row): ?ProductVariant
    {
        $query = ProductVariant::where('product_id', $productId)
            ->where('price', (float) ($row['price'] ?? 0));

        foreach (['height', 'width', 'length', 'weight'] as $field) {
            if (isset($row[$field]) && $row[$field] !== '') {
                $query->where($field, $row[$field]);
            } else {
                $query->whereNull($field);
            }
        }

        if (isset($row['sale_price']) && $row['sale_price'] !== '') {
            $query->where('sale_price', (float) $row['sale_price']);
        } else {
            $query->whereNull('sale_price');
        }

        return $query->first();
    }

    public function finalizeVariants(): void
    {
        foreach ($this->keptVariantIds as $productId => $variantIds) {
            ProductVariant::where('product_id', $productId)
                ->whereNotIn('id', $variantIds)
                ->delete();
        }

        $this->rowWriter->normalizeProductTypes();
    }

    protected function attachVariantAttributes(ProductVariant $variant, array $row): void
    {
        $attributesString = $row['attributes'] ?? '';

        if (empty(trim($attributesString))) {
            return;
        }

        $groups = explode('-', $attributesString);

        foreach ($groups as $group) {
            $group = trim($group);
            if (empty($group)) {
                continue;
            }

            $parts = explode(':', $group, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $namePart = trim($parts[0]);
            $valuePart = trim($parts[1]);

            if (empty($namePart) || empty($valuePart)) {
                continue;
            }

            $nameLanguages = explode('|', $namePart, 2);
            $valueLanguages = explode('|', $valuePart, 2);

            $enName = trim($nameLanguages[0]);
            $arName = trim($nameLanguages[1] ?? '');
            $enValue = trim($valueLanguages[0]);
            $arValue = trim($valueLanguages[1] ?? '');

            if (empty($enName)) {
                continue;
            }

            $attribute = Attribute::where('name->en', $enName)
                ->when($arName, fn($q) => $q->where('name->ar', $arName))
                ->first();

            if (!$attribute) {
                $name = ['en' => $enName];
                if ($arName) {
                    $name['ar'] = $arName;
                }
                $attribute = Attribute::create(['name' => $name]);
            }

            $attributeValue = AttributeValue::where('attribute_id', $attribute->id)
                ->where('value->en', $enValue)
                ->when($arValue, fn($q) => $q->where('value->ar', $arValue))
                ->first();

            if (!$attributeValue) {
                $value = ['en' => $enValue];
                if ($arValue) {
                    $value['ar'] = $arValue;
                }
                $attributeValue = AttributeValue::create([
                    'attribute_id' => $attribute->id,
                    'value' => $value,
                ]);
            }

            AttributeProduct::firstOrCreate([
                'product_variant_id' => $variant->id,
                'attribute_value_id' => $attributeValue->id,
            ]);
        }
    }

    public function processProductImage(string $productSku, string $imageUrl, ?int $rowIndex = null): void
    {
        $this->imageImporter()->import($productSku, $imageUrl, $rowIndex);

        $this->flushAuxProgress();
    }

    protected function imageImporter(): ImageImporter
    {
        if (!isset($this->imageImporter)) {
            $this->imageImporter = new ImageImporter($this->report);
        }

        return $this->imageImporter;
    }

    public function syncCategories(string $productSku, array $categorySlugs): void
    {
        $this->taxonomyLinker->sync(ProductWorkbookSchema::CATEGORIES, $productSku, $categorySlugs);
    }

    public function syncBrands(string $productSku, array $brandSlugs): void
    {
        $this->taxonomyLinker->sync(ProductWorkbookSchema::BRANDS, $productSku, $brandSlugs);
    }

    public function syncFlashSales(string $productSku, array $flashSaleSlugs): void
    {
        $this->taxonomyLinker->sync(ProductWorkbookSchema::FLASH_SALES, $productSku, $flashSaleSlugs);
    }

    public function syncSliders(string $productSku, array $sliderSlugs): void
    {
        $this->taxonomyLinker->sync(ProductWorkbookSchema::SLIDERS, $productSku, $sliderSlugs);
    }

    public function syncTags(string $productSku, array $tagSlugs): void
    {
        $this->taxonomyLinker->sync(ProductWorkbookSchema::TAGS, $productSku, $tagSlugs);
    }

    // Chunk-safe queue methods for large sheets (accumulate across chunks)
    public function queueCategories(string $productSku, array $categorySlugs): void
    {
        $this->taxonomyLinker->queue(ProductWorkbookSchema::CATEGORIES, $productSku, $categorySlugs);
    }

    public function queueBrands(string $productSku, array $brandSlugs): void
    {
        $this->taxonomyLinker->queue(ProductWorkbookSchema::BRANDS, $productSku, $brandSlugs);
    }

    public function queueFlashSales(string $productSku, array $flashSaleSlugs): void
    {
        $this->taxonomyLinker->queue(ProductWorkbookSchema::FLASH_SALES, $productSku, $flashSaleSlugs);
    }

    public function queueSliders(string $productSku, array $sliderSlugs): void
    {
        $this->taxonomyLinker->queue(ProductWorkbookSchema::SLIDERS, $productSku, $sliderSlugs);
    }

    public function queueTags(string $productSku, array $tagSlugs): void
    {
        $this->taxonomyLinker->queue(ProductWorkbookSchema::TAGS, $productSku, $tagSlugs);
    }

    public function flushPendingSyncs(): void
    {
        $this->taxonomyLinker->flush();
    }

    protected function flushProgress(): void
    {
        $this->progress->flush();
    }

    protected function flushAuxProgress(): void
    {
        $this->progress->flushAux();
    }

    protected function isCancelled(): bool
    {
        return $this->progress->isCancelled();
    }
}
