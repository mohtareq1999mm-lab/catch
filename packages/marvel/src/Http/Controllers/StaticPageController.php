<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Http\Resources\StaticPage\StaticPageResource;
use App\Http\Resources\StaticPage\StaticSectionResource;
use App\Services\General\StaticPageService;
use App\Traits\HasCache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\ReorderStaticSectionsRequest;
use Marvel\Http\Requests\StoreStaticSectionRequest;
use Marvel\Http\Requests\UpdateStaticPageRequest;
use Marvel\Http\Requests\UpdateStaticSectionRequest;
use Marvel\Traits\ApiResponse;
use Marvel\Database\Models\StaticPage;
use Marvel\Database\Models\StaticSection;
class StaticPageController extends CoreController
{
    use ApiResponse, HasCache;

    public function __construct(
        private StaticPageService $staticPageService
    ) {
        $this->middleware('permission:' . Permission::VIEW_STATIC_PAGES)->only(['index', 'show']);
        $this->middleware('permission:' . Permission::UPDATE_STATIC_PAGES)->only('update');
        $this->middleware('permission:' . Permission::CREATE_STATIC_SECTIONS)->only('storeSection');
        $this->middleware('permission:' . Permission::UPDATE_STATIC_SECTIONS)->only(['updateSection', 'reorderSections']);
        $this->middleware('permission:' . Permission::DELETE_STATIC_SECTIONS)->only('destroySection');
    }

    public function index()
    {
        $pages = $this->staticPageService->getAll();
        // Ensure media is eager loaded for resources (service already does, but keep defensive)
        $pages->loadMissing('staticSections.media');
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, StaticPageResource::collection($pages));
    }

    public function show(StaticPage $static_page)
    {
        $static_page->load(['staticSections.media']);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, StaticPageResource::make($static_page));
    }

    public function update(UpdateStaticPageRequest $request, StaticPage $static_page)
    {
        $page = $this->staticPageService->updatePage($static_page, $request->only(['title', 'is_active']));
        $this->flushTag(FrontendResource::STATIC_PAGES->value);
        return $this->apiResponse(STATIC_PAGE_UPDATED_SUCCESSFULLY, 200, true, StaticPageResource::make($page));
    }

    public function storeSection(StoreStaticSectionRequest $request, StaticPage $static_page)
    {
        $section = $this->staticPageService->createSection(
            $static_page,
            $request->validated(),
            $request->file('media')
        );
        $this->flushTag(FrontendResource::STATIC_PAGES->value);
        return $this->apiResponse(STATIC_SECTION_CREATED_SUCCESSFULLY, 200, true, StaticSectionResource::make($section->load('media')));
    }

    public function updateSection(UpdateStaticSectionRequest $request, StaticPage $static_page, StaticSection $static_section)
    {
        try {
            $section = $this->staticPageService->updateSection(
                $static_page,
                $static_section,
                $request->validated(),
                $request->file('media')
            );
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        }
        $this->flushTag(FrontendResource::STATIC_PAGES->value);
        return $this->apiResponse(STATIC_SECTION_UPDATED_SUCCESSFULLY, 200, true, StaticSectionResource::make($section->load('media')));
    }

    public function destroySection(StaticPage $static_page, StaticSection $static_section): JsonResponse
    {
        try {
            $this->staticPageService->deleteSection($static_page, $static_section);
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        $this->flushTag(FrontendResource::STATIC_PAGES->value);
        return $this->apiResponse(STATIC_SECTION_DELETED_SUCCESSFULLY, 200, true);
    }

    public function reorderSections(ReorderStaticSectionsRequest $request, StaticPage $static_page)
    {
        try {
            $this->staticPageService->reorderSections($static_page, $request->input('sections'));
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
        $this->flushTag(FrontendResource::STATIC_PAGES->value);
        return $this->apiResponse(STATIC_SECTIONS_REORDERED_SUCCESSFULLY, 200, true);
    }
}