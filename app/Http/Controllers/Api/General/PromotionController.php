<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Promotion\PromotionResource;
use App\Services\General\PromotionDataService;
use App\Traits\HasCache;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

class  PromotionController extends Controller
{
    use ApiResponse, HasCache;
    private PromotionDataService $promotionService;

    public function __construct(PromotionDataService $promotionService)
    {
        $this->promotionService = $promotionService;
    }

    public function index(Request $request)
    {
        if ($slug = $request->query('slug')) {
            $promotion = $this->promotionService->getPromotionBySlug($slug);
            if (!$promotion) {
                return $this->apiResponse(NOT_FOUND, 404, false);
            }
            if ($request->boolean('with_product') && $promotion->relationLoaded('products')) {
                $promotion->setRelation('products', $promotion->products->take(1));
            }
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, PromotionResource::make($promotion));
        }
        $promotions = $this->promotionService->paginatePromotion($request);
        $promotionsCache = $this->remember(FrontendResource::PROMOTIONS->value, md5($request->fullUrl()), $promotions);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, PromotionResource::collection($promotionsCache));
    }
    public function getPromotionBySlug($slug)
    {
        $PromotionWithProducts  = $this->promotionService->getPromotionBySlug($slug);
        if (!$PromotionWithProducts) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, PromotionResource::make($PromotionWithProducts));
    }
}
