<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Slider\SliderResource;
use App\Services\General\SliderService;
use App\Traits\HasCache;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

class SliderController extends Controller
{
    use ApiResponse , HasCache;
    private SliderService $sliderService;

    public function __construct(SliderService $sliderService)
    {
        $this->sliderService = $sliderService;
    }

    public function index(Request $request)
    {
        if ($slug = $request->query('slug')) {
            return $this->getSliderBySlug($slug);
        }
        $sliders = $this->sliderService->getSliders($request);
        $slidersCache = $this->remember(FrontendResource::SLIDERS->value, md5($request->fullUrl()), $sliders);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SliderResource::collection($slidersCache));
    }

    public function getSliderBySlug($slug)
    {
        $slider = $this->sliderService->getSliderBySlug($slug);
        if (!$slider) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SliderResource::make($slider));
    }
}
