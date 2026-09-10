<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Banner\BannerResource;
use App\Services\General\BannerService;
use App\Traits\HasCache;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

class BannerController extends Controller
{
    use ApiResponse, HasCache;
    private BannerService $bannerService;

    public function __construct(BannerService $bannerService)
    {
        $this->bannerService = $bannerService;
    }

    public function index(Request $request)
    {
        if ($slug = $request->query('slug')) {
            return $this->getBannerBySlug($slug, $request);
        }
        $banners =  $this->bannerService->getBanners($request);
        $bannerCache = $this->remember(FrontendResource::BANNERS->value, md5($request->fullUrl()), $banners);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true,  BannerResource::collection($bannerCache));
    }

    public function getBannerBySlug($slug, Request $request)
    {
        $with_products = $request->query('with_products', false);
        $banner =  $this->bannerService->getBannerBySlug($slug, $with_products);
        if (!$banner) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, BannerResource::make($banner));
    }


}
