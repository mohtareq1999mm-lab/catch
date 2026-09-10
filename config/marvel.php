<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Import Sample Files
    |--------------------------------------------------------------------------
    |
    | Paths to the sample Excel files for import templates.
    | These files are used by the downloadSample endpoints.
    |
    */
    'import' => [
        'samples' => [
            'product' => base_path('packages/marvel/resources/products/product-import-sample.xlsx'),
            'category' => base_path('packages/marvel/resources/categories/category-import-sample.xlsx'),
            'brand' => base_path('packages/marvel/resources/brands/brand-import-sample.xlsx'),
        ],
    ],
];
