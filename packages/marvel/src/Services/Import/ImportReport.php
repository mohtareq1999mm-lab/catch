<?php

namespace Marvel\Services\Import;

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

    /** @var array<int, array<string, mixed>> */
    private array $taxonomy = [];

    /**
     * @var array<string, array{label: string, slugs: array<string, array<int, string>>, skus: array<string, true>}>
     */
    private array $pendingTaxonomy = [];

    private int $inStockInconsistencies = 0;

    public function addProductRowError(int $row, string $sku, string $message): void
    {
        $this->productRows[] = $this->error('products', $row, $sku, $message);
    }

    public function addVariantError(int $row, string $sku, string $message): void
    {
        $this->variants[] = $this->error('product_variants', $row, $sku, $message);
    }

    public function addBarcodeError(int $row, string $sku, string $message): void
    {
        $this->barcodes[] = $this->error('product_barcodes', $row, $sku, $message);
    }

    public function addImageError(int $row, string $sku, string $message): void
    {
        $this->images[] = $this->error('images', $row, $sku, $message);
    }

    /**
     * @param  array<int, string>  $slugs
     */
    public function noteUnknownTaxonomySlugs(string $sheet, string $label, array $slugs, string $productSku): void
    {
        $this->pendingTaxonomy[$sheet] ??= ['label' => $label, 'slugs' => [], 'skus' => []];

        foreach ($slugs as $slug) {
            $this->pendingTaxonomy[$sheet]['slugs'][$slug][] = $productSku;
        }
    }

    public function noteMissingTaxonomyProduct(string $sheet, string $label, string $sku): void
    {
        $this->pendingTaxonomy[$sheet] ??= ['label' => $label, 'slugs' => [], 'skus' => []];
        $this->pendingTaxonomy[$sheet]['skus'][$sku] = true;
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
        $this->materializeTaxonomyErrors();

        $warnings = $this->inStockWarning();
        $warnings = $warnings === null ? [] : [$warnings];

        return array_merge($warnings, $this->productRows, $this->barcodes, $this->variants, $this->images, $this->taxonomy);
    }

    private function materializeTaxonomyErrors(): void
    {
        foreach ($this->pendingTaxonomy as $sheet => $issue) {
            foreach ($issue['slugs'] as $slug => $skus) {
                $this->taxonomy[] = $this->unknownSlugError($sheet, $issue['label'], $slug, $skus);
            }

            $missingProducts = array_keys($issue['skus']);

            if ($missingProducts !== []) {
                sort($missingProducts);
                $this->taxonomy[] = $this->error($sheet, 0, '', __(
                    'message.IMPORT.PRODUCT.MISSING_TAXONOMY_PRODUCTS',
                    [
                        'sku_count' => count($missingProducts),
                        'sku_list' => $this->preview($missingProducts),
                    ]
                ));
            }
        }

        $this->pendingTaxonomy = [];
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

        return implode(', ', $shown) . ($hidden > 0 ? " … (+{$hidden} more)" : '');
    }
}
