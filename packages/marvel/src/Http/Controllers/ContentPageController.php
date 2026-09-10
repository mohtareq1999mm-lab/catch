<?php

namespace Marvel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Enums\FrontendResource;
use App\Http\Resources\Pages\ContentPageResource;
use App\Http\Resources\Pages\SectionResource;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\StoreContentPageRequest;
use Marvel\Http\Requests\UpdateContentPageRequest;
use Marvel\Http\Requests\AttachSectionsRequest;
use Marvel\Models\ContentPage;
use Marvel\Traits\ApiResponse;

class ContentPageController extends Controller
{
    use ApiResponse, HasCache;

    public function __construct()
    {
        $this->middleware('permission:' . Permission::VIEW_CONTENT_PAGES)->only(['index', 'show']);
        $this->middleware('permission:' . Permission::CREATE_CONTENT_PAGES)->only('store');
        $this->middleware('permission:' . Permission::UPDATE_CONTENT_PAGES)->only(['update', 'attachSections', 'toggleActive']);
        $this->middleware('permission:' . Permission::DELETE_CONTENT_PAGES)->only('destroy');
    }
    public function index(Request $request)
    {
        $pages = ContentPage::with([
            'sections' => function ($query) {
                $query->with('sectionType.settings');
            }
        ])->paginate(15);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ContentPageResource::collection($pages));
    }

    public function show(ContentPage $content_page)
    {
        $content_page->load([
            'sections' => function ($query) {
                $query->with('sectionType.settings');
            }
        ]);
        return   $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ContentPageResource::make($content_page));
    }

    public function store(StoreContentPageRequest $request)
    {

        $data = $request->only(['title']);
        $data['slug'] = Str::slug($data['title']['en']);
        $page = ContentPage::create($data + ['is_active' => true]);

        return $this->apiResponse(CREATE_DATA_SUCCESSFULLY, 201, true, ContentPageResource::make($page));
    }

    public function update(UpdateContentPageRequest $request, $id)
    {
        $content_page = ContentPage::find($id);
        if (!$content_page) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        $content_page->update($request->only(['title', 'is_active']));
        $content_page->load([
            'sections' => function ($query) {
                $query->with('sectionType.settings');
            }
        ]);

        return  $this->apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, ContentPageResource::make($content_page));
    }

    /**
     * Attach existing sections to the page by IDs provided in request.sections
     */
    public function attachSections(AttachSectionsRequest $request, ContentPage $content_page)
    {
            // invalidate the frontend content pages cache. This is required here in
            // addition to the observers because the detach path uses a query builder
            // update which does not fire Eloquent model events.
            $this->flushTag(FrontendResource::CONTENT_PAGES->value);

            $sectionIds = $request->input('sections', []);

            // if empty array provided, delete the content page as requested
            if (empty($sectionIds)) {
                $content_page->sections()->update(['content_page_id' => null]);
                return $this->apiResponse(DELETE_DATA_SUCCESSFULLY, 200, true);
            }

            $attached = $content_page->attachSectionsByIds($sectionIds);
            $content_page->load([
                'sections' => function ($query) {
                    $query->with('sectionType.settings');
                }
            ]);
            return $this->apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, ContentPageResource::make($content_page));
        
    }

    public function destroy(ContentPage $content_page): JsonResponse
    {
        $content_page->delete();
        return $this->apiResponse(DELETE_DATA_SUCCESSFULLY, 200, true);
    }

    public function toggleActive(ContentPage $content_page): JsonResponse
    {
        $content_page->is_active = !$content_page->is_active;
        $content_page->save();
        return $this->apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, ContentPageResource::make($content_page));
    }
}
