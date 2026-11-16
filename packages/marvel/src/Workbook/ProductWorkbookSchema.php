<?php

namespace Marvel\Workbook;

use InvalidArgumentException;
use Marvel\Imports\Sheets\FlashSalesSheetImport;
use Marvel\Imports\Sheets\ImagesSheetImport;
use Marvel\Imports\Sheets\ProductBarcodesSheetImport;
use Marvel\Imports\Sheets\ProductBrandsSheetImport;
use Marvel\Imports\Sheets\ProductCategoriesSheetImport;
use Marvel\Imports\Sheets\ProductsSheetImport;
use Marvel\Imports\Sheets\ProductVariantsSheetImport;
use Marvel\Imports\Sheets\SlidersSheetImport;
use Marvel\Imports\Sheets\TagsSheetImport;
use Marvel\Exports\Sheets\BrandsSheetExport;
use Marvel\Exports\Sheets\CategoriesSheetExport;
use Marvel\Exports\Sheets\FlashSalesSheetExport;
use Marvel\Exports\Sheets\ImagesSheetExport;
use Marvel\Exports\Sheets\ProductBarcodesSheetExport;
use Marvel\Exports\Sheets\ProductsSheetExport;
use Marvel\Exports\Sheets\ProductVariantsSheetExport;
use Marvel\Exports\Sheets\SlidersSheetExport;
use Marvel\Exports\Sheets\TagsSheetExport;

/**
 * The product workbook contract: one definition per sheet.
 *
 * The Excel import, the asynchronous image path, row counting, exports and
 * sample checks all read sheet identity from here. Renaming a column or
 * changing a chunk size must be a single edit in this class.
 */
final class ProductWorkbookSchema
{
    public const PRODUCTS = 'products';

    public const PRODUCT_BARCODES = 'product_barcodes';

    public const PRODUCT_VARIANTS = 'product_variants';

    public const IMAGES = 'images';

    public const CATEGORIES = 'categories';

    public const BRANDS = 'brands';

    public const FLASH_SALES = 'flash_sales';

    public const SLIDERS = 'sliders';

    public const TAGS = 'tags';

    /**
     * @var array<string, array{
     *     title: string,
     *     importer: class-string,
     *     exporter: class-string,
     *     key_column: string,
     *     chunk_size: int,
     *     dispatch_chunk_size?: int,
     *     headings: array<int, string>
     * }>
     */
    private const SHEETS = [
        self::PRODUCTS => [
            'title' => 'products',
            'importer' => ProductsSheetImport::class,
            'exporter' => ProductsSheetExport::class,
            'key_column' => 'sku',
            'chunk_size' => 1000,
            'headings' => [
                'sku',
                'name_en',
                'name_ar',
                'description_en',
                'description_ar',
                'price',
                'product_type',
                'item_type',
                'quantity',
                'status',
                'in_stock',
                'has_discount',
                'discount_type',
                'discount_amount',
                'start_date',
                'end_date',
                'height',
                'width',
                'length',
                'weight',
                'tax_enabled',
                'tax_rate',
                'pieces',
                'has_flash_sale',
            ],
        ],
        self::PRODUCT_BARCODES => [
            'title' => 'product_barcodes',
            'importer' => ProductBarcodesSheetImport::class,
            'exporter' => ProductBarcodesSheetExport::class,
            'key_column' => 'product_sku',
            'chunk_size' => 500,
            'headings' => ['product_sku', 'piece_barcode', 'carton_barcode', 'pieces_per_carton'],
        ],
        self::PRODUCT_VARIANTS => [
            'title' => 'product_variants',
            'importer' => ProductVariantsSheetImport::class,
            'exporter' => ProductVariantsSheetExport::class,
            'key_column' => 'product_sku',
            'chunk_size' => 1000,
            'headings' => [
                'variant_sku',
                'product_sku',
                'price',
                'sale_price',
                'quantity',
                'in_stock',
                'height',
                'width',
                'length',
                'weight',
                'attributes',
            ],
        ],
        self::CATEGORIES => [
            'title' => 'categories',
            'importer' => ProductCategoriesSheetImport::class,
            'exporter' => CategoriesSheetExport::class,
            'key_column' => 'product_sku',
            'chunk_size' => 500,
            'headings' => ['product_sku', 'category_slug'],
        ],
        self::BRANDS => [
            'title' => 'brands',
            'importer' => ProductBrandsSheetImport::class,
            'exporter' => BrandsSheetExport::class,
            'key_column' => 'product_sku',
            'chunk_size' => 500,
            'headings' => ['product_sku', 'brand_slug'],
        ],
        self::FLASH_SALES => [
            'title' => 'flash_sales',
            'importer' => FlashSalesSheetImport::class,
            'exporter' => FlashSalesSheetExport::class,
            'key_column' => 'product_sku',
            'chunk_size' => 500,
            'headings' => ['product_sku', 'flash_sale_slug'],
        ],
        self::SLIDERS => [
            'title' => 'sliders',
            'importer' => SlidersSheetImport::class,
            'exporter' => SlidersSheetExport::class,
            'key_column' => 'product_sku',
            'chunk_size' => 500,
            'headings' => ['product_sku', 'slider_slug'],
        ],
        self::TAGS => [
            'title' => 'tags',
            'importer' => TagsSheetImport::class,
            'exporter' => TagsSheetExport::class,
            'key_column' => 'product_sku',
            'chunk_size' => 500,
            'headings' => ['product_sku', 'tag_slug'],
        ],
        // Images last: ProductsImport historically appends this sheet last.
        self::IMAGES => [
            'title' => 'images',
            'importer' => ImagesSheetImport::class,
            'exporter' => ImagesSheetExport::class,
            'key_column' => 'product_sku',
            'chunk_size' => 200,
            'dispatch_chunk_size' => 500,
            'headings' => ['product_sku', 'image'],
        ],
    ];

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::SHEETS);
    }

    public static function title(string $sheet): string
    {
        return self::definition($sheet)['title'];
    }

    /**
     * @return class-string
     */
    public static function importer(string $sheet): string
    {
        return self::definition($sheet)['importer'];
    }

    /**
     * @return array<string, class-string>
     */
    public static function importers(): array
    {
        $importers = [];

        foreach (self::SHEETS as $key => $definition) {
            $importers[$key] = $definition['importer'];
        }

        return $importers;
    }

    /**
     * @return array<string, class-string>
     */
    public static function exporters(): array
    {
        $exporters = [];

        foreach (self::SHEETS as $key => $definition) {
            $exporters[$key] = $definition['exporter'];
        }

        return $exporters;
    }

    public static function keyColumn(string $sheet): string
    {
        return self::definition($sheet)['key_column'];
    }

    public static function chunkSize(string $sheet): int
    {
        return self::definition($sheet)['chunk_size'];
    }

    /**
     * Rows per dispatched async job. Defaults to the Excel read chunk size.
     */
    public static function dispatchChunkSize(string $sheet): int
    {
        $definition = self::definition($sheet);

        return $definition['dispatch_chunk_size'] ?? $definition['chunk_size'];
    }

    /**
     * The full export/sample column contract for a sheet.
     *
     * @return array<int, string>
     */
    public static function headings(string $sheet): array
    {
        return self::definition($sheet)['headings'];
    }

    /**
     * Image cells are either a single `image` value or a pipe-separated
     * `images` value. The single column wins when both are filled.
     *
     * @return array<int, string>
     */
    public static function imageColumns(): array
    {
        return ['image', 'images'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    public static function imageUrls(array $row): array
    {
        [$singleColumn, $pipeColumn] = self::imageColumns();

        if (!empty($row[$singleColumn])) {
            return [(string) $row[$singleColumn]];
        }

        if (empty($row[$pipeColumn])) {
            return [];
        }

        return array_values(array_filter(array_map(
            'trim',
            explode('|', (string) $row[$pipeColumn])
        )));
    }

    /**
     * @return array{title: string, importer: class-string, exporter: class-string, key_column: string, chunk_size: int, dispatch_chunk_size?: int, headings: array<int, string>}
     */
    private static function definition(string $sheet): array
    {
        if (!isset(self::SHEETS[$sheet])) {
            throw new InvalidArgumentException("Unknown product workbook sheet '{$sheet}'.");
        }

        return self::SHEETS[$sheet];
    }
}