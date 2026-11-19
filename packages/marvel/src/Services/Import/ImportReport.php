<?php

namespace Marvel\Services\Import;

use Marvel\Workbook\ProductWorkbookSchema;

/**
 * Accumulates the outcome of one product import.
 *
 * Lives beside ProductImportService because it is bookkeeping for the vendored
 * import pipeline, not domain logic. Only product-row failures move the import
 * counters; variant, barcode, image and taxonomy failures belong in the
 * downloadable report without inflating processed/success/failed (4104→4913).
 */
final class ImportReport
{
    private const PREVIEW_LIMIT = 20;

    /** @var array<int, array<string, mixed>> */
    private array $productRows = [];

    /** @var array<int, array<string, mixed>> */
    private array $variants = [];

    /** @var array<int, array<string, mixed>> */
    private array $barcodes = [];

    /** @var array<int, array<string, mixed>> */
    private array $images = [];

    /**
     * Unknown slug -> affected product SKUs, per sheet. Kept as a map so every
     * call to all() is idempotent and later chunks merge into the same entry.
     *
     * @var array<string, array{label: string, slugs: array<string, array<string, true>>}>
     */
    private array $unknownSlugs = [];

    /**
     * @var array<string, array{label: string, skus: array<string, true>}>
     */
    private array $missingTaxonomyProducts = [];

    private int $inStockInconsistencies = 0;

    public function addProductRowError(int $row, string $sku, string $message): void
    {
        $this->productRows[] = $this->error(
            ProductWorkbookSchema::title(ProductWorkbookSchema::PRODUCTS),
            $row,
            $sku,
            $message
        );
    }

    public function addVariantError(int $row, string $sku, string $message): void
    {
        $this->variants[] = $this->error(
            ProductWorkbookSchema::title(ProductWorkbookSchema::PRODUCT_VARIANTS),
            $row,
            $sku,
            $message
        );
    }

    public function addBarcodeError(int $row, string $sku, string $message): void
    {
        $this->barcodes[] = $this->error(
            ProductWorkbookSchema::title(ProductWorkbookSchema::PRODUCT_BARCODES),
            $row,
            $sku,
            $message
        );
    }

    public function addImageError(int $row, string $sku, string $message): void
    {
        $this->images[] = $this->error(
            ProductWorkbookSchema::title(ProductWorkbookSchema::IMAGES),
            $row,
            $sku,
            $message
        );
    }

    /**
     * @param  array<int, string>  $slugs
     */
    public function noteUnknownTaxonomySlugs(string $sheet, string $label, array $slugs, string $productSku): void
    {
        $this->unknownSlugs[$sheet] ??= ['label' => $label, 'slugs' => []];

        foreach ($slugs as $slug) {
            $this->unknownSlugs[$sheet]['slugs'][$slug][$productSku] = true;
        }
    }

    public function noteMissingTaxonomyProduct(string $sheet, string $label, string $sku): void
    {
        $this->missingTaxonomyProducts[$sheet] ??= ['label' => $label, 'skus' => []];
        $this->missingTaxonomyProducts[$sheet]['skus'][$sku] = true;
    }

    public function noteInStockInconsistency(): void
    {
        $this->inStockInconsistencies++;
    }

    public function productRowErrors(): array
    {
        return $this->productRows;
    }

    public function variantErrors(): array
    {
        return $this->variants;
    }

    public function barcodeErrors(): array
    {
        return $this->barcodes;
    }

    public function imageErrors(): array
    {
        return $this->images;
    }

    /**
     * The single error-merge rule for the persisted import error list: decode
     * a persisted (possibly JSON-encoded) value, append the incoming bucket
     * and apply the single cap, so per-chunk jobs (e.g. image chunks) can
     * append without duplicating the rule or growing the list past the cap.
     *
     * @param  mixed  $persisted  Raw import->errors value (array or JSON string)
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<int, array<string, mixed>>
     */
    public static function mergePersisted($persisted, array $incoming): array
    {
        if (is_string($persisted)) {
            $persisted = json_decode($persisted, true);
        }

        if (!is_array($persisted)) {
            $persisted = [];
        }

        return array_slice(array_merge($persisted, $incoming), 0, RunOutcome::ERROR_CAP);
    }

    public function failedRowCount(): int
    {
        return count($this->productRows);
    }

    /**
     * Full downloadable report: aggregated warnings first so a large product
     * error list cannot push them past the persistence cap, then the failures.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $warnings = $this->inStockWarning();
        $warnings = $warnings === null ? [] : [$warnings];

        return array_merge(
            $warnings,
            $this->productRows,
            $this->barcodes,
            $this->variants,
            $this->images,
            $this->taxonomyErrors()
        );
    }

    /**
     * Built from the maps on every call, so repeated calls are idempotent and
     * later chunks merge into the same per-slug entry.
     *
     * @return array<int, array<string, mixed>>
     */
    private function taxonomyErrors(): array
    {
        $errors = [];

        foreach ($this->unknownSlugs as $sheet => $issue) {
            foreach ($issue['slugs'] as $slug => $skus) {
                $errors[] = $this->unknownSlugError($sheet, $issue['label'], $slug, array_keys($skus));
            }
        }

        foreach ($this->missingTaxonomyProducts as $sheet => $issue) {
            $skus = array_keys($issue['skus']);
            sort($skus);

            $errors[] = $this->error($sheet, 0, '', __(
                'message.IMPORT.PRODUCT.MISSING_TAXONOMY_PRODUCTS',
                [
                    'sku_count' => count($skus),
                    'sku_list' => $this->preview($skus),
                ]
            ));
        }

        return $errors;
    }

    /**
     * @param  array<int, string>  $affectedSkus
     * @return array<string, mixed>
     */
    private function unknownSlugError(string $sheet, string $label, string $slug, array $affectedSkus): array
    {
        $affectedSkus = array_values(array_unique($affectedSkus));
        sort($affectedSkus);

        return $this->error($sheet, 0, $affectedSkus[0] ?? '', __(
            'message.IMPORT.PRODUCT.UNKNOWN_TAXONOMY_SLUG',
            [
                'slug' => $slug,
                'taxonomy_label' => $label,
                'sku_count' => count($affectedSkus),
                'sku_list' => $this->preview($affectedSkus),
            ]
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inStockWarning(): ?array
    {
        if ($this->inStockInconsistencies === 0) {
            return null;
        }

        return $this->error('products', 0, '', __(
            'message.IMPORT.PRODUCT.IN_STOCK_INCONSISTENCY',
            ['row_count' => $this->inStockInconsistencies]
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $sheet, int $row, string $sku, string $message): array
    {
        return [
            'sheet' => $sheet,
            'row' => $row,
            'sku' => $sku,
            'error_message' => $message,
        ];
    }

    /**
     * @param  array<int, string>  $values
     */
    private function preview(array $values): string
    {
        $shown = array_slice($values, 0, self::PREVIEW_LIMIT);
        $hidden = count($values) - count($shown);

        return implode(', ', $shown) . ($hidden > 0 ? ' ' . __('message.IMPORT.PRODUCT.MORE_ITEMS', ['count' => $hidden]) : '');
    }
}
