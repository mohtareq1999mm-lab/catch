<?php

namespace Tests\Unit\Import;

use InvalidArgumentException;
use Marvel\Workbook\ProductWorkbookSchema;
use Marvel\Imports\Sheets\ChunkedSheetImport;
use Tests\TestCase;

class ProductWorkbookSchemaTest extends TestCase
{
    public function test_every_sheet_exposes_identity_and_a_chunked_importer(): void
    {
        $titles = [];

        foreach (ProductWorkbookSchema::keys() as $sheet) {
            $this->assertNotSame('', ProductWorkbookSchema::title($sheet));
            $this->assertGreaterThan(0, ProductWorkbookSchema::chunkSize($sheet));
            $this->assertTrue(is_subclass_of(ProductWorkbookSchema::importer($sheet), ChunkedSheetImport::class));
            $this->assertContains(ProductWorkbookSchema::keyColumn($sheet), ProductWorkbookSchema::headings($sheet));

            $importerSheet = (new \ReflectionClass(ProductWorkbookSchema::importer($sheet)))
                ->getConstant('SHEET');
            $this->assertSame($sheet, $importerSheet, 'Each importer must declare the schema key it handles.');

            $titles[] = ProductWorkbookSchema::title($sheet);
        }

        $this->assertSame($titles, array_values(array_unique($titles)), 'Sheet titles must be unique.');
    }

    public function test_image_urls_accept_single_and_pipe_separated_cells(): void
    {
        $this->assertSame(
            ['https://example.com/a.jpg'],
            ProductWorkbookSchema::imageUrls(['image' => 'https://example.com/a.jpg', 'images' => ''])
        );

        $this->assertSame(
            ['https://example.com/b.jpg', 'https://example.com/c.jpg'],
            ProductWorkbookSchema::imageUrls(['image' => '', 'images' => 'https://example.com/b.jpg|https://example.com/c.jpg'])
        );

        $this->assertSame(
            ['https://example.com/a.jpg'],
            ProductWorkbookSchema::imageUrls(['image' => 'https://example.com/a.jpg', 'images' => 'https://example.com/b.jpg'])
        );

        $this->assertSame([], ProductWorkbookSchema::imageUrls(['image' => '', 'images' => '']));
    }

    public function test_images_use_separate_read_and_dispatch_chunk_sizes(): void
    {
        $this->assertSame(200, ProductWorkbookSchema::chunkSize(ProductWorkbookSchema::IMAGES));
        $this->assertSame(500, ProductWorkbookSchema::dispatchChunkSize(ProductWorkbookSchema::IMAGES));
        $this->assertSame(
            ProductWorkbookSchema::chunkSize(ProductWorkbookSchema::PRODUCTS),
            ProductWorkbookSchema::dispatchChunkSize(ProductWorkbookSchema::PRODUCTS)
        );
    }

    public function test_an_unknown_sheet_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProductWorkbookSchema::title('not_a_sheet');
    }
}
