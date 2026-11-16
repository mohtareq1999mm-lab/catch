<?php

namespace Marvel\Services\Import;

use Illuminate\Database\Eloquent\Model;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\Tag;
use Marvel\Workbook\ProductWorkbookSchema;

/**
 * Owns the taxonomy link step of a product import: queued slugs per sheet,
 * slug resolution, gap reporting and the product link sync.
 *
 * A missing reference must not abort the product import (the product row is
 * already valid), but it can no longer disappear silently.
 */
final class ImportTaxonomyLinker
{
    /**
     * Taxonomy sheet contract: translated label key, Eloquent relation, lookup model.
     *
     * @var array<string, array{label_key: string, relation: string, model: class-string<Model>}>
     */
    private const TAXONOMY_SHEETS = [
        ProductWorkbookSchema::CATEGORIES => ['label_key' => 'message.IMPORT.PRODUCT.TAXONOMY.CATEGORY', 'relation' => 'categories', 'model' => Category::class],
        ProductWorkbookSchema::BRANDS => ['label_key' => 'message.IMPORT.PRODUCT.TAXONOMY.BRAND', 'relation' => 'brands', 'model' => Brand::class],
        ProductWorkbookSchema::FLASH_SALES => ['label_key' => 'message.IMPORT.PRODUCT.TAXONOMY.FLASH_SALE', 'relation' => 'flash_sales', 'model' => FlashSale::class],
        ProductWorkbookSchema::SLIDERS => ['label_key' => 'message.IMPORT.PRODUCT.TAXONOMY.SLIDER', 'relation' => 'sliders', 'model' => Slider::class],
        ProductWorkbookSchema::TAGS => ['label_key' => 'message.IMPORT.PRODUCT.TAXONOMY.TAG', 'relation' => 'tags', 'model' => Tag::class],
    ];

    /**
     * Pending slug lists per taxonomy sheet, accumulated across chunks.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $pendingSlugs = [];

    public function __construct(private readonly ImportReport $report)
    {
    }

    public function queue(string $sheet, string $productSku, array $slugs): void
    {
        if (empty($productSku) || empty($slugs)) {
            return;
        }

        $existing = $this->pendingSlugs[$sheet][$productSku] ?? [];
        $this->pendingSlugs[$sheet][$productSku] = array_values(array_unique(array_merge($existing, $slugs)));
    }

    public function sync(string $sheet, string $productSku, array $slugs): void
    {
        $config = self::TAXONOMY_SHEETS[$sheet];
        $label = __($config['label_key']);
        $product = $this->findProduct($productSku, $sheet, $label);

        if (!$product) {
            return;
        }

        $ids = $this->resolveIds($sheet, $slugs, $productSku);

        if ($ids !== []) {
            $product->{$config['relation']}()->sync($ids);
        }
    }

    public function flush(): void
    {
        foreach ($this->pendingSlugs as $sheet => $perSku) {
            foreach ($perSku as $sku => $slugs) {
                $this->sync($sheet, (string) $sku, $slugs);
            }
        }

        $this->pendingSlugs = [];
    }

    /**
     * Resolve slugs to ids and report references the catalog cannot satisfy.
     *
     * @return array<int, int>
     */
    private function resolveIds(string $sheet, array $slugs, string $productSku): array
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

    private function findProduct(string $productSku, string $sheet, string $label): ?Product
    {
        $product = Product::where('sku', $productSku)->first();

        if (!$product) {
            $this->report->noteMissingTaxonomyProduct($sheet, $label, $productSku);
        }

        return $product;
    }
}
