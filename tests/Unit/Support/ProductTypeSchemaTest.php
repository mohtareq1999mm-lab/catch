<?php

namespace Tests\Unit\Support;

use App\Support\ProductTypeSchema;
use Tests\TestCase;

class ProductTypeSchemaTest extends TestCase
{
    public function test_canonical_enum_text_comes_from_the_values(): void
    {
        $this->assertSame("ENUM('simple','variable')", (new ProductTypeSchema())->canonicalEnum());
    }

    public function test_canonical_schema_is_compatible(): void
    {
        $this->assertNull((new ProductTypeSchema())->incompatibilityReason());
    }
}
