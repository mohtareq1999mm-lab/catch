<?php


namespace App\Services\General\ProductEngine\Strategies;

use App\Services\General\ProductEngine\Contract\ProductTypeStrategy;
use App\Services\General\ProductService;

class ProductHasFlashSaleEndToday implements ProductTypeStrategy
{
    protected $productService;
    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }
    public function getProducts($request)
    {
        return $this->productService->getFlashSaleProductsEndingToday($request);
    }
}
