<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pages\ContentPageResource;
use App\Traits\HasCache;
use Marvel\Models\ContentPage;
use Marvel\Traits\ApiResponse;

class ContentPageController extends Controller
{
    use ApiResponse, HasCache;
    public function index()
    {
        // Lazy closure: the paginator query runs ONLY on a cache miss
        // (G2-F-01 fix). Cached payload is the same paginator object that the
        // former pre-built form cached — resource mapping below is unchanged.
        $pagesCache = $this->remember(
            FrontendResource::CONTENT_PAGES->value,
            md5(request()->fullUrl()),
            fn () => ContentPage::where('is_active', true)->with([
                'sections' => fn ($query) => $query->where('is_active', true)->with('sectionType.settings'),
            ])->paginate(15)
        );
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ContentPageResource::collection($pagesCache));
    }

    public function show($slug)
    {
        // Lazy closure: detail query (incl. firstOrFail) runs ONLY on a cache
        // miss; 404s stay uncached by design and keep the unified F2 envelope
        // (G2-SHOW fix — previously every cached-hit request still queried DB).
        $contentPageCache = $this->remember(
            FrontendResource::CONTENT_PAGES->value,
            md5(request()->fullUrl()),
            fn () => ContentPage::where('slug', $slug)->where('is_active', true)->with([
                'sections' => fn ($query) => $query->where('is_active', true)->with('sectionType.settings'),
            ])->firstOrFail()
        );
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ContentPageResource::make($contentPageCache));
    }
}