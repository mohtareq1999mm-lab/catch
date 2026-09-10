<?php

namespace Marvel\Services\Import;

use App\Events\FileOperationEvent;
use App\Traits\BroadcastsFileOperationProgress;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeProduct;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\Tag;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ProductType;
use Marvel\Exceptions\ImportCancelledException;
use Marvel\Services\Import\ImageHandlers\UrlImageHandler;
use Marvel\Services\Pricing\ProductPricingService;

class ProductImportService
{
    use BroadcastsFileOperationProgress;

    protected ?UrlImageHandler $urlHandler = null;

    protected ProductPricingService $pricingService;

    protected array $failedRows = [];

    protected int $successCount = 0;

    // Product is the canonical work unit (products sheet data rows).
    // Variants and images are auxiliary: their failures must NOT inflate
    // product-row counters (see BE-012, 4104→4913 forensic). They are
    // tracked separately for error download but excluded from progress.
    protected array $variantErrors = [];
    protected int $variantSuccessCount = 0;
    protected array $imageErrors = [];

    protected array $keptVariantIds = [];

    protected array $createdProductIds = [];

    protected array $pendingCategorySlugs = [];
    protected array $pendingBrandSlugs = [];
    protected array $pendingFlashSaleSlugs = [];
    protected array $pendingSliderSlugs = [];
    protected array $pendingTagSlugs = [];

    protected ?int $importId = null;

    protected int $processedCount = 0;

    protected float $startedAt;

    protected float $lastTickTime;

    protected int $lastTickProcessedCount = 0;

    protected float $currentProgress = 0.0;

    protected int $knownTotalRows = 0;

    protected const FLUSH_THRESHOLD = 10;

    protected const SIGNAL_DIR = 'imports';

    public function __construct(?int $importId = null, ?ProductPricingService $pricingService = null)
    {
        $this->urlHandler = new UrlImageHandler();
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
        return $this->failedRows;
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getVariantErrors(): array
    {
        return $this->variantErrors;
    }

    public function getVariantSuccessCount(): int
    {
        return $this->variantSuccessCount;
    }

    public function getImageErrors(): array
    {
        return $this->imageErrors;
    }

    public function getAllErrors(): array
    {
        return array_merge($this->failedRows, $this->variantErrors, $this->imageErrors);
    }

    public function getAllErrorCount(): int
    {
        return count($this->failedRows) + count($this->variantErrors) + count($this->imageErrors);
    }

    protected function flushProgress(): void
    {
        if ($this->importId === null) {
            return;
        }

        $this->processedCount = $this->successCount + count($this->failedRows);

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
                count($this->failedRows)
            );
        }

        $this->writeSignal('progress', [
            'processed_rows' => $this->processedCount,
            'success_rows' => $this->successCount,
            'failed_rows' => count($this->failedRows),
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
        $this->lastTickProcessedCount = $this->successCount + count($this->failedRows);
        $this->lastTickTime = microtime(true);
        $this->writeSignal('progress', [
            'processed_rows' => $this->successCount + count($this->failedRows),
            'success_rows' => $this->successCount,
            'failed_rows' => count($this->failedRows),
            'progress' => $progress,
        ]);

        $this->broadcastFileOperationProgress(
            FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
            'product-import',
            (int) $this->importId,
            $progress,
            $this->successCount + count($this->failedRows),
            $this->successCount,
            count($this->failedRows)
        );
    }

    public function setTotalRows(int $total): void
    {
        $this->knownTotalRows = max(0, $total);
    }

    protected function calculateSmoothProgress(): float
    {
        $processed = $this->successCount + count($this->failedRows);
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
            $processed = $this->successCount + count($this->failedRows);
            if ($this->knownTotalRows > 0) {
                $processed = min($processed, $this->knownTotalRows);
            }

            $query->update([
                'processed_rows' => $processed,
                'success_rows' => min($this->successCount, $this->knownTotalRows > 0 ? $this->knownTotalRows : $this->successCount),
                'failed_rows' => min(count($this->failedRows), $this->knownTotalRows > 0 ? $this->knownTotalRows : count($this->failedRows)),
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

        $this->processedCount = $this->successCount + count($this->failedRows);

        if ($this->isCancelled()) {
            throw new ImportCancelledException();
        }

        Import::where('id', $this->importId)
            ->update([
                'processed_rows' => $this->processedCount,
                'success_rows' => $this->successCount,
                'failed_rows' => count($this->failedRows),
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

            $sku = $row['sku'] ?? null;
            $product = null;

            if (!empty($sku)) {
                $product = Product::where('sku', $sku)->first();
            }

            $data = $this->buildProductData($row);

            if (empty($sku)) {
                $data['sku'] = 'PRD-' . Str::uuid();
            } else {
                $data['sku'] = $sku;
            }

            if ($product) {
                
                $data['slug'] = $product->slug;

                // D5 — item_type immutability for existing products.
                if (isset($data['item_type']) && $data['item_type'] !== $product->item_type && \Marvel\Database\Models\OrderProduct::where('product_id', $product->id)->exists()) {
                    throw new \InvalidArgumentException(__('message.ERROR.ITEM_TYPE_IMMUTABLE_ORDERED'));
                }

                $product->fill($data)->saveQuietly();
            } else {
                $data['slug'] = $this->generateSlug($row, $product?->id);
                $product = new Product($data);
                $product->saveQuietly();
                $this->createdProductIds[] = $product->id;
            }

            if (!empty($sku) && $product->sku !== $sku) {
                $product->sku = $sku;
                $product->saveQuietly();
            }

            $pricing = $this->pricingService->calculateProductPricingFromData(
                $product->toArray(),
                $product->getActiveFlashSale()
            );
            $product->fill([
                'price_after_discount' => $pricing['price_after_discount'] ?? null,
                'price_after_flash_sale' => $pricing['price_after_flash_sale'] ?? null,
            ])->saveQuietly();

            DB::commit();
            $this->successCount++;
        } catch (Exception $e) {
            DB::rollBack();
            $this->failedRows[] = [
                'sheet' => 'products',
                'row' => $rowIndex,
                'sku' => $row['sku'] ?? 'N/A',
                'error_message' => $e->getMessage(),
            ];

        }

        $this->flushProgress();
    }

    public function processVariantRow(array $row, int $rowIndex): void
    {
        $productSku = $row['product_sku'] ?? null;
        if (empty($productSku)) {
            return;
        }

        $product = Product::where('sku', $productSku)->first();
        if (!$product) {
            $this->variantErrors[] = [
                'sheet' => 'product_variants',
                'row' => $rowIndex,
                'sku' => $productSku,
                'error_message' => "Product with SKU '{$productSku}' not found",
            ];
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

            DB::commit();

            $product->product_type = ProductType::VARIABLE;
            $product->saveQuietly();

            $this->variantSuccessCount++;
        } catch (Exception $e) {
            DB::rollBack();
            $this->variantErrors[] = [
                'sheet' => 'product_variants',
                'row' => $rowIndex,
                'sku' => $productSku,
                'error_message' => $e->getMessage(),
            ];

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
            $this->imageErrors[] = [
                'sheet' => 'images',
                'row' => $rowIndex ?? 0,
                'sku' => $productSku,
                'error_message' => "Product with SKU '{$productSku}' not found for image '{$imageUrl}'",
            ];
            $this->flushImageProgress();
            return;
        }

        try {
            $handled = false;
            if ($this->urlHandler && $this->urlHandler->isValidUrl($imageUrl)) {
                $downloaded = $this->urlHandler->download($imageUrl);
                if ($downloaded) {
                    $this->urlHandler->attachToModel($product, $downloaded, 'products');
                    $this->urlHandler->cleanup($downloaded);
                    $handled = true;
                } else {
                    $this->imageErrors[] = [
                        'sheet' => 'images',
                        'row' => $rowIndex ?? 0,
                        'sku' => $productSku,
                        'error_message' => "Failed to download image '{$imageUrl}': invalid response or exceeds limits",
                    ];
                }
            } elseif (file_exists($imageUrl)) {
                $product->addMedia($imageUrl)->toMediaCollection('products');
                $handled = true;
            } else {
                $this->imageErrors[] = [
                    'sheet' => 'images',
                    'row' => $rowIndex ?? 0,
                    'sku' => $productSku,
                    'error_message' => "Invalid image URL or file not found: '{$imageUrl}'",
                ];
            }
            // Images are auxiliary: success silent, failure non-fatal (see BrandImportService)
            // Must NOT inflate product-row counters (4104→4913)
            $this->flushImageProgress();
        } catch (Exception $e) {
            $this->imageErrors[] = [
                'sheet' => 'images',
                'row' => $rowIndex ?? 0,
                'sku' => $productSku,
                'error_message' => "Image processing failed for '{$imageUrl}': " . $this->sanitizeErrorMessage($e),
            ];
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
        $product = Product::where('sku', $productSku)->first();
        if (!$product) {
            return;
        }

        $categoryIds = Category::whereIn('slug', $categorySlugs)->pluck('id')->toArray();
        if (!empty($categoryIds)) {
            $product->categories()->sync($categoryIds);
        }
    }

    public function syncBrands(string $productSku, array $brandSlugs): void
    {
        $product = Product::where('sku', $productSku)->first();
        if (!$product) {
            return;
        }

        $brandIds = Brand::whereIn('slug', $brandSlugs)->pluck('id')->toArray();
        if (!empty($brandIds)) {
            $product->brands()->sync($brandIds);
        }
    }

    public function syncFlashSales(string $productSku, array $flashSaleSlugs): void
    {
        $product = Product::where('sku', $productSku)->first();
        if (!$product) {
            return;
        }

        $flashSaleIds = FlashSale::whereIn('slug', $flashSaleSlugs)->pluck('id')->toArray();
        if (!empty($flashSaleIds)) {
            $product->flash_sales()->sync($flashSaleIds);
        }
    }

    public function syncSliders(string $productSku, array $sliderSlugs): void
    {
        $product = Product::where('sku', $productSku)->first();
        if (!$product) {
            return;
        }

        $sliderIds = Slider::whereIn('slug', $sliderSlugs)->pluck('id')->toArray();
        if (!empty($sliderIds)) {
            $product->sliders()->sync($sliderIds);
        }
    }

    public function syncTags(string $productSku, array $tagSlugs): void
    {
        $product = Product::where('sku', $productSku)->first();
        if (!$product) {
            return;
        }

        $tagIds = Tag::whereIn('slug', $tagSlugs)->pluck('id')->toArray();
        if (!empty($tagIds)) {
            $product->tags()->sync($tagIds);
        }
    }

    // Chunk-safe queue methods for large sheets (accumulate across chunks)
    public function queueCategories(string $productSku, array $categorySlugs): void
    {
        if (empty($productSku) || empty($categorySlugs)) return;
        $existing = $this->pendingCategorySlugs[$productSku] ?? [];
        $this->pendingCategorySlugs[$productSku] = array_values(array_unique(array_merge($existing, $categorySlugs)));
    }

    public function queueBrands(string $productSku, array $brandSlugs): void
    {
        if (empty($productSku) || empty($brandSlugs)) return;
        $existing = $this->pendingBrandSlugs[$productSku] ?? [];
        $this->pendingBrandSlugs[$productSku] = array_values(array_unique(array_merge($existing, $brandSlugs)));
    }

    public function queueFlashSales(string $productSku, array $flashSaleSlugs): void
    {
        if (empty($productSku) || empty($flashSaleSlugs)) return;
        $existing = $this->pendingFlashSaleSlugs[$productSku] ?? [];
        $this->pendingFlashSaleSlugs[$productSku] = array_values(array_unique(array_merge($existing, $flashSaleSlugs)));
    }

    public function queueSliders(string $productSku, array $sliderSlugs): void
    {
        if (empty($productSku) || empty($sliderSlugs)) return;
        $existing = $this->pendingSliderSlugs[$productSku] ?? [];
        $this->pendingSliderSlugs[$productSku] = array_values(array_unique(array_merge($existing, $sliderSlugs)));
    }

    public function queueTags(string $productSku, array $tagSlugs): void
    {
        if (empty($productSku) || empty($tagSlugs)) return;
        $existing = $this->pendingTagSlugs[$productSku] ?? [];
        $this->pendingTagSlugs[$productSku] = array_values(array_unique(array_merge($existing, $tagSlugs)));
    }

    public function flushPendingSyncs(): void
    {
        foreach ($this->pendingCategorySlugs as $sku => $slugs) {
            $this->syncCategories($sku, $slugs);
        }
        foreach ($this->pendingBrandSlugs as $sku => $slugs) {
            $this->syncBrands($sku, $slugs);
        }
        foreach ($this->pendingFlashSaleSlugs as $sku => $slugs) {
            $this->syncFlashSales($sku, $slugs);
        }
        foreach ($this->pendingSliderSlugs as $sku => $slugs) {
            $this->syncSliders($sku, $slugs);
        }
        foreach ($this->pendingTagSlugs as $sku => $slugs) {
            $this->syncTags($sku, $slugs);
        }
        $this->pendingCategorySlugs = [];
        $this->pendingBrandSlugs = [];
        $this->pendingFlashSaleSlugs = [];
        $this->pendingSliderSlugs = [];
        $this->pendingTagSlugs = [];
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
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_PRICE'));
            }
            $data['price'] = (float) $row['price'];
        }

        if (isset($row['product_type'])) {
            if (!in_array($row['product_type'], ProductType::getValues(), true)) {
                throw new \InvalidArgumentException(
                    "Invalid product_type '{$row['product_type']}'. Allowed: " . implode(', ', ProductType::getValues())
                );
            }
            $data['product_type'] = $row['product_type'];
        }

        if (isset($row['item_type'])) {
            $itemType = strtoupper(trim((string) $row['item_type']));

            if (!in_array($itemType, \Marvel\Enums\ItemType::getValues(), true)) {
                // Invalid values are rejected — never silently defaulted.
                throw new \InvalidArgumentException(
                    "Invalid item_type '{$row['item_type']}'. Allowed: " . implode(', ', \Marvel\Enums\ItemType::getValues())
                );
            }

            $data['item_type'] = $itemType;
        }

        if (isset($row['quantity'])) {
            if (!is_numeric($row['quantity']) || (int) $row['quantity'] < 0) {
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_QUANTITY'));
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
                    throw new \InvalidArgumentException("Invalid tax_rate '{$row['tax_rate']}'. Must be 0..100.");
                }
                $data['tax_rate'] = (float) $row['tax_rate'];
            }
        }

        if (isset($row['status'])) {
            $data['status'] = $this->parseBoolean($row['status']);
        }

        if (isset($row['in_stock'])) {
            $data['in_stock'] = $this->parseBoolean($row['in_stock']);
        }

        if (isset($row['has_discount'])) {
            $data['has_discount'] = $this->parseBoolean($row['has_discount']);
        }

        if (isset($row['discount_type'])) {
            if (!in_array($row['discount_type'], DiscountType::getValues(), true)) {
                throw new \InvalidArgumentException(
                    "Invalid discount_type '{$row['discount_type']}'. Allowed: " . implode(', ', DiscountType::getValues())
                );
            }
            $data['discount_type'] = $row['discount_type'];
        }

        if (isset($row['discount_amount'])) {
            if (!is_numeric($row['discount_amount']) || (float) $row['discount_amount'] < 0) {
                throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_DISCOUNT_AMOUNT'));
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

    protected function validateNumeric($value, string $field, bool $allowFloat = false, bool $isInteger = false)
    {
        if ($value === null || $value === '') {
            return $isInteger ? 0 : 0.0;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.INVALID_NUMERIC_FIELD', ['field' => $field]));
        }

        $numeric = $isInteger ? (int) $value : (float) $value;

        if ($numeric < 0) {
            throw new \InvalidArgumentException(__('message.IMPORT.PRODUCT.NEGATIVE_VALUE_NOT_ALLOWED', ['field' => $field]));
        }

        return $numeric;
    }

    protected function generateSlug(array $row, ?int $existingId = null): string
    {
        $baseSlug = Str::slug($row['name_en'] ?? $row['sku'] ?? 'product-' . Str::random(6));
        $slug = $baseSlug;
        $count = 1;

        while (Product::where('slug', $slug)->when($existingId, fn($q, $id) => $q->where('id', '!=', $id))->exists()) {
            $slug = $baseSlug . '-' . $count++;
        }

        return $slug;
    }

    protected function parseBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'publish', 'approved']);
        }
        return false;
    }
}
