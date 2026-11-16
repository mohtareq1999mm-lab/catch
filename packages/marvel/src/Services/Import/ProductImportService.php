<?php

namespace Marvel\Services\Import;

use App\Events\FileOperationEvent;
use App\Traits\BroadcastsFileOperationProgress;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeProduct;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\Tag;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ProductType;
use Marvel\Exceptions\ImportCancelledException;
use Marvel\Services\Import\ImageHandlers\UrlImageHandler;
use Marvel\Services\Pricing\ProductPricingService;
use Marvel\Workbook\ProductWorkbookSchema;

class ProductImportService
{
    use BroadcastsFileOperationProgress;

    protected ?UrlImageHandler $urlHandler = null;

    protected ProductPricingService $pricingService;

    protected ImportReport $report;

    /**
     * Product types already verified against the database (one probe per value,
     * not one per row).
     *
     * @var array<string, true>
     */
    protected array $verifiedProductTypes = [];

    protected int $successCount = 0;

    // Product is the canonical work unit (products sheet data rows).
    // Variants, barcodes, images and taxonomy are auxiliary: their failures
    // must NOT inflate product-row counters (see BE-012, 4104→4913 forensic).
    protected int $variantSuccessCount = 0;
    protected int $barcodeSuccessCount = 0;

    protected array $keptVariantIds = [];

    protected array $createdProductIds = [];

    /**
     * Pending slug lists per taxonomy sheet, accumulated across chunks.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $pendingTaxonomySlugs = [];

    protected ?int $importId = null;

    protected int $processedCount = 0;

    protected float $startedAt;

    protected float $lastTickTime;

    protected int $lastTickProcessedCount = 0;

    protected float $currentProgress = 0.0;

    protected int $knownTotalRows = 0;

    protected const FLUSH_THRESHOLD = 10;

    protected const SIGNAL_DIR = 'imports';

    /**
     * Taxonomy sheet contract: translated label key, Eloquent relation, lookup model.
     *
     * @var array<string, array{label_key: string, relation: string, model: class-string<\Illuminate\Database\Eloquent\Model>}>
     */
    private const TAXONOMY_SHEETS = [
        ProductWorkbookSchema::CATEGORIES => ['label_key' => 'message.IMPORT.PRODUCT.TAXONOMY.CATEGORY', 'relation' => 'categories', 'model' => Category::class],
        ProductWorkbookSchema::BRANDS => ['label_key' => 'message.IMPORT.PRODUCT.TAXONOMY.BRAND', 'relation' => 'brands', 'model' => Brand::class],
        ProductWorkbookSchema::FLASH_SALES => ['label_key' => 'message.IMPORT.PRODUCT.TAXONOMY.FLASH_SALE', 'relation' => 'flash_sales', 'model' => FlashSale::class],
        ProductWorkbookSchema::SLIDERS => ['label_key' => 'message.IMPORT.PRODUCT.TAXONOMY.SLIDER', 'relation' => 'sliders', 'model' => Slider::class],
        ProductWorkbookSchema::TAGS => ['label_key' => 'message.IMPORT.PRODUCT.TAXONOMY.TAG', 'relation' => 'tags', 'model' => Tag::class],
    ];

    public function __construct(?int $importId = null, ?ProductPricingService $pricingService = null)
    {
        $this->urlHandler = new UrlImageHandler();
        $this->report = new ImportReport();
        $this->importId = $importId;
        $this->pricingService = $pricingService ?? app(ProductPricingService::class);
        $now = microtime(true);
        $this->startedAt = $now;
        $this->lastTickTime = $now;
        $this->ensureSignalDir();
    }

    protected function ensureSignalDir(): void
    {
        $dir = storage_path('app/' . self::SIGNAL_DIR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    protected function signalPath(string $type): ?string
    {
        if ($this->importId === null) {
            return null;
        }
        return storage_path('app/' . self::SIGNAL_DIR) . '/' . $type . '_' . $this->importId . '.json';
    }

    protected function writeSignal(string $type, array $data = []): void
    {
        $path = $this->signalPath($type);
        if ($path === null) {
            return;
        }
        try {
            file_put_contents($path, json_encode($data));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function readSignal(string $type): ?array
    {
        $path = $this->signalPath($type);
        if ($path === null || !file_exists($path)) {
            return null;
        }
        try {
            $contents = file_get_contents($path);
            return json_decode($contents, true) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function removeSignal(string $type): void
    {
        $path = $this->signalPath($type);
        if ($path === null) {
            return;
        }
        try {
            if (file_exists($path)) {
                @unlink($path);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function getFailedRows(): array
    {
        return $this->report->productRowErrors();
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
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

    protected function flushProgress(): void
    {
        if ($this->importId === null) {
            return;
        }

        $this->processedCount = $this->successCount + $this->report->failedRowCount();

        $rowsSinceLastTick = $this->processedCount - $this->lastTickProcessedCount;
        $timeSinceLastTick = microtime(true) - $this->lastTickTime;

        if ($rowsSinceLastTick >= 10 || $timeSinceLastTick >= 30) {
            $this->currentProgress = $this->calculateSmoothProgress();
            $this->lastTickProcessedCount = $this->processedCount;
            $this->lastTickTime = microtime(true);

            $this->broadcastFileOperationProgress(
                FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
                'product-import',
                (int) $this->importId,
                $this->currentProgress,
                $this->processedCount,
                $this->successCount,
                $this->report->failedRowCount()
            );
        }

        $this->writeSignal('progress', [
            'processed_rows' => $this->processedCount,
            'success_rows' => $this->successCount,
            'failed_rows' => $this->report->failedRowCount(),
            'progress' => $this->currentProgress,
        ]);

        if ($this->isCancelled()) {
            $this->writeProgress(true);
            throw new ImportCancelledException();
        }

        if ($this->processedCount % self::FLUSH_THRESHOLD === 0) {
            $this->writeProgress();
        }
    }

    /**
     * Heartbeat for auxiliary sheets (variants/images).
     * Must NOT mutate product-row counters (see 4104→4913 forensic).
     * Only checks cancellation and refreshes signal heartbeat.
     */
    protected function flushAuxProgress(): void
    {
        if ($this->importId === null) {
            return;
        }
        if ($this->isCancelled()) {
            $this->writeProgress(true);
            throw new ImportCancelledException();
        }
        // Refresh heartbeat without inflating counters; keep product progress signal fresh
        // Do NOT call writeProgress() here – auxiliary work is not counted.
    }

    protected function flushVariantProgress(): void
    {
        $this->flushAuxProgress();
    }

    protected function flushImageProgress(): void
    {
        $this->flushAuxProgress();
    }

    public function writeExplicitProgress(float $progress): void
    {
        if ($this->importId === null) {
            return;
        }
        $this->currentProgress = $progress;
        $this->lastTickProcessedCount = $this->successCount + $this->report->failedRowCount();
        $this->lastTickTime = microtime(true);
        $this->writeSignal('progress', [
            'processed_rows' => $this->successCount + $this->report->failedRowCount(),
            'success_rows' => $this->successCount,
            'failed_rows' => $this->report->failedRowCount(),
            'progress' => $progress,
        ]);

        $this->broadcastFileOperationProgress(
            FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
            'product-import',
            (int) $this->importId,
            $progress,
            $this->successCount + $this->report->failedRowCount(),
            $this->successCount,
            $this->report->failedRowCount()
        );
    }

    public function setTotalRows(int $total): void
    {
        $this->knownTotalRows = max(0, $total);
    }

    protected function calculateSmoothProgress(): float
    {
        $processed = $this->successCount + $this->report->failedRowCount();
        if ($this->knownTotalRows > 0) {
            // Honest progress: processed / total * 99, capped
            $real = ($processed / $this->knownTotalRows) * 99.0;
            return round(min(max($real, 0.0), 99.0), 2);
        }
        $elapsed = max(microtime(true) - $this->startedAt, 0);
        $timeBased = 99.0 * (1 - exp(-$elapsed / 60));
        $rowBased = 99.0 * (2 / M_PI) * atan($processed / 200);
        $progress = max($timeBased, $rowBased);
        return round(min($progress, 99.0), 2);
    }

    protected function writeProgress(bool $ignoreStatus = false): void
    {
        try {
            $query = Import::where('id', $this->importId);

            if (!$ignoreStatus) {
                $query->where('status', 'processing');
            }

            // Invariant: processed = success + failed, must not exceed knownTotalRows
            // Clamp in DB write to prevent runaway due to auxiliary sheets (see 4104→4913)
            $processed = $this->successCount + $this->report->failedRowCount();
            if ($this->knownTotalRows > 0) {
                $processed = min($processed, $this->knownTotalRows);
            }

            $query->update([
                'processed_rows' => $processed,
                'success_rows' => min($this->successCount, $this->knownTotalRows > 0 ? $this->knownTotalRows : $this->successCount),
                'failed_rows' => min($this->report->failedRowCount(), $this->knownTotalRows > 0 ? $this->knownTotalRows : $this->report->failedRowCount()),
                // Persist errors incrementally so API does not return failed_rows>0 with errors=null (4104→4913)
                'errors' => array_slice($this->getAllErrors(), 0, 1000),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function finalizeProgress(): void
    {
        if ($this->importId === null) {
            return;
        }

        $this->processedCount = $this->successCount + $this->report->failedRowCount();

        if ($this->isCancelled()) {
            throw new ImportCancelledException();
        }

        Import::where('id', $this->importId)
            ->update([
                'processed_rows' => $this->processedCount,
                'success_rows' => $this->successCount,
                'failed_rows' => $this->report->failedRowCount(),
                'errors' => array_slice($this->getAllErrors(), 0, 1000),
            ]);
    }

    public function getCreatedProductIds(): array
    {
        return $this->createdProductIds;
    }

    public function getKeptVariantIds(): array
    {
        return $this->keptVariantIds;
    }

    protected function isCancelled(): bool
    {
        if ($this->importId === null) {
            return false;
        }

        return file_exists(storage_path('app/' . self::SIGNAL_DIR) . '/cancel_' . $this->importId . '.json');
    }

    public function rollbackCreatedData(): void
    {
        foreach ($this->keptVariantIds as $productId => $variantIds) {
            AttributeProduct::whereIn('product_variant_id', $variantIds)->delete();
            ProductVariant::whereIn('id', $variantIds)->forceDelete();
        }

        if (!empty($this->createdProductIds)) {
            \App\Models\ProductBarcode::whereIn('product_id', $this->createdProductIds)->delete();
            $createdProducts = Product::whereIn('id', $this->createdProductIds)->get();
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
            $this->successCount++;
        } catch (Exception $e) {
            DB::rollBack();
            $this->report->addProductRowError(
                $rowIndex,
                (string) ($row['sku'] ?? 'N/A'),
                $e->getMessage()
            );
        }

        $this->flushProgress();
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    private function saveProduct(array $row, array $productData): Product
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

            if ($this->itemTypeIsImmutable($product, $productData)) {
                throw new \InvalidArgumentException(__('message.ERROR.ITEM_TYPE_IMMUTABLE_ORDERED'));
            }

            $product->fill($productData)->saveQuietly();
        }

        return $product;
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
            $this->flushVariantProgress();
            return;
        }

        try {
            DB::beginTransaction();

            $variant = $this->findVariantByFields($product->id, $row);

            $variantData = [
                'product_id' => $product->id,
                'sku' => $row['variant_sku'] ?? null,
                'price' => $this->validateNumeric($row['price'] ?? null, 'price', true),
                'sale_price' => isset($row['sale_price']) && $row['sale_price'] !== '' ? $this->validateNumeric($row['sale_price'], 'sale_price', true) : null,
                'stock_quantity' => $this->validateNumeric($row['quantity'] ?? null, 'quantity', false, true),
                'quantity' => $this->validateNumeric($row['quantity'] ?? null, 'quantity', false, true),
                'in_stock' => $this->parseBoolean($row['in_stock'] ?? true),
                'height' => $row['height'] ?? null,
                'width' => $row['width'] ?? null,
                'length' => $row['length'] ?? null,
                'weight' => $row['weight'] ?? null,
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
            $this->assertPersistedProductType($product, ProductType::VARIABLE);

            DB::commit();

            $this->variantSuccessCount++;
        } catch (Exception $e) {
            DB::rollBack();
            $this->report->addVariantError($rowIndex, (string) $productSku, $e->getMessage());
        }

        $this->flushVariantProgress();
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
        $imageUrl = trim($imageUrl);
        if (empty($imageUrl)) {
            return;
        }

        $product = Product::where('sku', $productSku)->first();
        if (!$product) {
            $this->report->addImageError(
                $rowIndex ?? 0,
                $productSku,
                __('message.IMPORT.PRODUCT.PRODUCT_NOT_FOUND', ['sku' => $productSku])
            );
            $this->flushImageProgress();
            return;
        }

        try {
            $handled = false;
            if ($this->urlHandler && $this->urlHandler->isValidUrl($imageUrl)) {
                $downloaded = $this->urlHandler->download($imageUrl);
                if ($downloaded) {
                    // Idempotency: skip if identical file already attached (hash comparison)
                    if ($this->isDuplicateMedia($product, $downloaded)) {
                        $this->urlHandler->cleanup($downloaded);
                        $handled = true;
                    } else {
                        $this->urlHandler->attachToModel($product, $downloaded, 'products');
                        $this->urlHandler->cleanup($downloaded);
                        $handled = true;
                    }
                } else {
                    $this->report->addImageError(
                        $rowIndex ?? 0,
                        $productSku,
                        __('message.IMPORT.PRODUCT.IMAGE_DOWNLOAD_FAILED', ['image_url' => $imageUrl])
                    );
                }
            } elseif (file_exists($imageUrl)) {
                if ($this->isDuplicateMedia($product, $imageUrl)) {
                    $handled = true;
                } else {
                    $product->addMedia($imageUrl)->toMediaCollection('products');
                    $handled = true;
                }
            } else {
                $this->report->addImageError(
                    $rowIndex ?? 0,
                    $productSku,
                    __('message.IMPORT.PRODUCT.IMAGE_INVALID_URL', ['image_url' => $imageUrl])
                );
            }
            // Images are auxiliary: success silent, failure non-fatal (see BrandImportService)
            // Must NOT inflate product-row counters (4104→4913)
            $this->flushImageProgress();
        } catch (Exception $e) {
            $this->report->addImageError(
                $rowIndex ?? 0,
                $productSku,
                __('message.IMPORT.PRODUCT.IMAGE_PROCESSING_FAILED', [
                    'image_url' => $imageUrl,
                    'error_message' => $this->sanitizeErrorMessage($e),
                ])
            );
            $this->flushImageProgress();
        }
    }

    protected function sanitizeErrorMessage(Throwable $e): string
    {
        $msg = $e->getMessage();
        $msg = preg_replace('#/[^ ]*storage[^ ]*#i', '[storage path]', $msg) ?? $msg;
        $msg = preg_replace('#SQLSTATE\[[^\]]+\].*#i', 'Internal processing error', $msg) ?? $msg;
        if (strlen($msg) > 300) $msg = substr($msg, 0, 300) . '...';
        return trim($msg) !== '' ? trim($msg) : 'Unexpected error';
    }

    public function syncCategories(string $productSku, array $categorySlugs): void
    {
        $this->syncTaxonomy('categories', $productSku, $categorySlugs);
    }

    public function syncBrands(string $productSku, array $brandSlugs): void
    {
        $this->syncTaxonomy('brands', $productSku, $brandSlugs);
    }

    public function syncFlashSales(string $productSku, array $flashSaleSlugs): void
    {
        $this->syncTaxonomy('flash_sales', $productSku, $flashSaleSlugs);
    }

    public function syncSliders(string $productSku, array $sliderSlugs): void
    {
        $this->syncTaxonomy('sliders', $productSku, $sliderSlugs);
    }

    public function syncTags(string $productSku, array $tagSlugs): void
    {
        $this->syncTaxonomy('tags', $productSku, $tagSlugs);
    }

    private function syncTaxonomy(string $sheet, string $productSku, array $slugs): void
    {
        $config = self::TAXONOMY_SHEETS[$sheet];
        $label = __($config['label_key']);
        $product = $this->findProductForTaxonomy($productSku, $sheet, $label);

        if (!$product) {
            return;
        }

        $ids = $this->resolveTaxonomyIds($sheet, $slugs, $productSku);

        if ($ids !== []) {
            $product->{$config['relation']}()->sync($ids);
        }
    }

    /**
     * Resolve slugs to ids and report references the catalog cannot satisfy.
     * A missing reference must not abort the product import (the product row is
     * already valid), but it can no longer disappear silently.
     *
     * @return array<int, int>
     */
    private function resolveTaxonomyIds(string $sheet, array $slugs, string $productSku): array
    {
        $slugs = $this->normalizeSlugs($slugs);

        if ($slugs === []) {
            return [];
        }

        $config = self::TAXONOMY_SHEETS[$sheet];
        $idsBySlug = $config['model']::whereIn('slug', $slugs)->pluck('id', 'slug');
        $missing = array_values(array_udiff($slugs, $idsBySlug->keys()->all(), 'strcasecmp'));

        if ($missing !== []) {
            $this->report->noteUnknownTaxonomySlugs($sheet, __($config['label_key']), $missing, $productSku);
        }

        return $idsBySlug->values()->all();
    }

    /**
     * @param  array<int, mixed>  $slugs
     * @return array<int, string>
     */
    private function normalizeSlugs(array $slugs): array
    {
        $trimmed = array_map(static fn ($slug): string => trim((string) $slug), $slugs);

        return array_values(array_unique(array_filter($trimmed, static fn (string $slug): bool => $slug !== '')));
    }

    protected function findProductForTaxonomy(string $productSku, string $sheet, string $label): ?Product
    {
        $product = Product::where('sku', $productSku)->first();

        if (!$product) {
            $this->report->noteMissingTaxonomyProduct($sheet, $label, $productSku);
        }

        return $product;
    }

    // Chunk-safe queue methods for large sheets (accumulate across chunks)
    public function queueCategories(string $productSku, array $categorySlugs): void
    {
        $this->queueTaxonomy('categories', $productSku, $categorySlugs);
    }

    public function queueBrands(string $productSku, array $brandSlugs): void
    {
        $this->queueTaxonomy('brands', $productSku, $brandSlugs);
    }

    public function queueFlashSales(string $productSku, array $flashSaleSlugs): void
    {
        $this->queueTaxonomy('flash_sales', $productSku, $flashSaleSlugs);
    }

    public function queueSliders(string $productSku, array $sliderSlugs): void
    {
        $this->queueTaxonomy('sliders', $productSku, $sliderSlugs);
    }

    public function queueTags(string $productSku, array $tagSlugs): void
    {
        $this->queueTaxonomy('tags', $productSku, $tagSlugs);
    }

    /**
     * @param  array<int, string>  $slugs
     */
    private function queueTaxonomy(string $sheet, string $productSku, array $slugs): void
    {
        if (empty($productSku) || empty($slugs)) {
            return;
        }

        $existing = $this->pendingTaxonomySlugs[$sheet][$productSku] ?? [];
        $this->pendingTaxonomySlugs[$sheet][$productSku] = array_values(array_unique(array_merge($existing, $slugs)));
    }

    public function flushPendingSyncs(): void
    {
        foreach ($this->pendingTaxonomySlugs as $sheet => $perSku) {
            foreach ($perSku as $sku => $slugs) {
                $this->syncTaxonomy($sheet, (string) $sku, $slugs);
            }
        }

        $this->pendingTaxonomySlugs = [];
    }

    protected function buildProductData(array $row): array
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
            if (!in_array($row['discount_type'], DiscountType::getValues(), true)) {
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_DISCOUNT_TYPE', [
                    'given_value' => $row['discount_type'],
                    'allowed_values' => implode(', ', DiscountType::getValues()),
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
                $data[$field] = (string) $row[$field];
            }
        }

        if (isset($row['pieces'])) {
            if (!is_numeric($row['pieces']) || (int) $row['pieces'] < 0) {
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_PIECES'));
            }
            $data['pieces'] = (int) $row['pieces'];
        }

        if (isset($row['has_flash_sale'])) {
            $data['has_flash_sale'] = $this->parseBoolean($row['has_flash_sale']);
        }

        return $data;
    }

    /**
     * Verify the ENUM write actually landed as requested.
     *
     * MySQL with strict mode off silently turns a value the ENUM cannot
     * represent into '', which then looks like "no product type" everywhere
     * downstream. Fail the row instead of leaving corrupt catalog data behind.
     */
    protected function assertPersistedProductType(Product $product, ?string $expected): void
    {
        $cacheKey = $expected ?? '__default__';

        if (isset($this->verifiedProductTypes[$cacheKey])) {
            return;
        }

        // Read the physical column, bypassing model scopes: the guard exists to
        // prove what the database stored, not what the current channel can see.
        $stored = DB::table('products')->where('id', $product->getKey())->value('product_type');
        $columnContract = $this->productTypeColumnContract();

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

    private function productTypeColumnContract(): string
    {
        $values = implode("','", ProductType::getValues());

        return "ENUM('{$values}')";
    }

    protected function validateNumeric($value, string $field, bool $allowFloat = false, bool $isInteger = false)
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

    protected function parseBoolean($value): bool
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

    /**
     * Check if the given file is already attached to the product (by content hash) to ensure re-import idempotency.
     */
    protected function isDuplicateMedia(Product $product, string $filePath): bool
    {
        try {
            if (!file_exists($filePath)) {
                return false;
            }
            $newHash = @md5_file($filePath);
            if ($newHash === false) {
                return false;
            }
            $product->loadMissing('media');
            foreach ($product->getMedia('products') as $media) {
                $existingPath = $media->getPath();
                if (!file_exists($existingPath)) {
                    continue;
                }
                $existingHash = @md5_file($existingPath);
                if ($existingHash !== false && hash_equals($existingHash, $newHash)) {
                    return true;
                }
                // Fallback: same file size + same mime exact length comparison already covered by hash
            }
        } catch (\Throwable $e) {
            report($e);
        }
        return false;
    }
}
