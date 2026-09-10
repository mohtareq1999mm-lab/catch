<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Brand\BrandProductResource;
use App\Http\Resources\Brand\BrandResource;
use App\Services\Currency\CurrencyService;
use App\Services\General\BrandService;
use App\Traits\HasCache;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

class BrandController extends Controller
{
    use ApiResponse, HasCache;
    private BrandService $brandService;

    public function __construct(BrandService $brandService)
    {
        $this->brandService = $brandService;
    }

    public function index(Request $request)
    {
        if ($slug = $request->query('slug')) {
            return $this->getBrandBySlug($slug);
        }
        $brands =  $this->brandService->getBrands($request);
        $brandCache = $this->remember(FrontendResource::BRANDS->value, md5($request->fullUrl()), $brands);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true,  BrandResource::collection($brandCache));
    }

    public function getBrandBySlug($slug)
    {
        // Products are enriched with pricing via BrandService::getBrandBySlug →
        // ProductService::enrichCollectionWithPricing, and converted via
        // BrandProductResource::convertCatalogPrice using CurrencyService.
        // No direct guest_currency read here; CurrencyService is the single source.
        $brand =  $this->brandService->getBrandBySlug($slug);
        if (!$brand) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, BrandResource::make($brand));
    }

    public function getBrandsProductsByQtySet(Request $request)
    {
        // Currency-aware cache — product prices are converted via
        // ConvertsProductPrice using CurrencyService::getEffectiveCode().
        $key = $this->currencyAwareCacheKey($request);
        $brandWithProducts = $this->remember(
            FrontendResource::BRANDS_PRODUCTS->value,
            $key,
            fn () => $this->brandService->getBrandsProductsByQtySet($request)
        );

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, BrandProductResource::collection($brandWithProducts));
    }

    private function currencyAwareCacheKey(Request $request): string
    {
        return md5($request->fullUrl() . '|currency:' . app(CurrencyService::class)->getEffectiveCode());
    }
}