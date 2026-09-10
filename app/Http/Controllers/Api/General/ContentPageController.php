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
        $pages = ContentPage::where('is_active', true)->with([
            'sections' => function ($query) {
                $query->where('is_active', true)->with('sectionType.settings');
            }
        ])->paginate(15);
        $pagesCache = $this->remember(FrontendResource::CONTENT_PAGES->value, md5(request()->fullUrl()), $pages);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ContentPageResource::collection($pagesCache));
    }

    public function show($slug)
    {
        $content_page = ContentPage::where('slug', $slug)->where('is_active', true)->with([
            'sections' => function ($query) {
                $query->where('is_active', true)->with('sectionType.settings');
            }
        ])->firstOrFail();
        $contentPageCache = $this->remember(FrontendResource::CONTENT_PAGES->value, md5(request()->fullUrl()), $content_page);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ContentPageResource::make($contentPageCache));
    }
}