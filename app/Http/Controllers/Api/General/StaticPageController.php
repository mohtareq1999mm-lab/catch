<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaticPage\StaticPageResource;
use App\Traits\HasCache;
use Marvel\Database\Models\StaticPage;
use Marvel\Traits\ApiResponse;

class StaticPageController extends Controller
{
    use ApiResponse, HasCache;

    public function index()
    {
        $pagesCache = $this->remember(
            FrontendResource::STATIC_PAGES->value,
            md5(request()->fullUrl()),
            fn () => StaticPage::where('is_active', true)->with(['staticSections' => function ($q) {
                $q->where('is_active', true)->with('media')->orderBy('order');
            }])->get()
        );
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, StaticPageResource::collection($pagesCache));
    }

    public function show($slug)
    {
        $staticPageCache = $this->remember(
            FrontendResource::STATIC_PAGES->value,
            md5(request()->fullUrl()),
            fn () => StaticPage::where('slug', $slug)->where('is_active', true)->with(['staticSections' => function ($q) {
                $q->where('is_active', true)->with('media')->orderBy('order');
            }])->firstOrFail()
        );
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, StaticPageResource::make($staticPageCache));
    }
}