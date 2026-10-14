<?php

namespace Marvel\Http\Controllers;

use App\Http\Resources\Pages\SectionResource as PagesSectionResource;
use App\Enums\FrontendResource;
use App\Services\General\SectionTypeService;
use App\Traits\HasCache;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\StoreSectionRequest;
use Marvel\Http\Requests\UpdateSectionRequest;
use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Marvel\Traits\ApiResponse;

class SectionController extends CoreController
{
    use ApiResponse, HasCache;

    public function __construct(
        private SectionTypeService $sectionTypeService
    ) {
        $this->middleware('permission:' . Permission::VIEW_SECTIONS)->only(['index', 'show', 'getTypeSection']);
        $this->middleware('permission:' . Permission::CREATE_SECTIONS)->only('store');
        $this->middleware('permission:' . Permission::UPDATE_SECTIONS)->only(['update', 'reorder', 'toggleStatus']);
        $this->middleware('permission:' . Permission::DELETE_SECTIONS)->only('destroy');
    }

    public function index()
    {
        $sections = Section::ordered()->with('sectionType.settings')->get();
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, PagesSectionResource::collection($sections));
    }

    public function store(StoreSectionRequest $request)
    {
        $data = $request->validated();
        $data['endpoint'] = $data['endpoint'] ?? 'general/' . $data['type'];

        if (! isset($data['title']) && $request->has('title')) {
            $data['title'] = $request->input('title');
        }

        $section = Section::create($data);
        $page = ContentPage::first();
        if ($page) {
            $section->update(['content_page_id' => $page->id]);
        }
        $section->loadMissing('sectionType.settings');

        return $this->apiResponse(SECTION_CREATED_SUCCESSFULLY, 200, true, PagesSectionResource::make($section));
    }

    public function show(Section $section)
    {
        $section->loadMissing('sectionType.settings');
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, PagesSectionResource::make($section));
    }

    public function update(UpdateSectionRequest $request, Section $section)
    {
        $data = $request->validated();

        if (! isset($data['title']) && $request->has('title')) {
            $data['title'] = $request->input('title');
        }

        $section->update($data);
        $section->loadMissing('sectionType.settings');

        return $this->apiResponse(SECTION_UPDATED_SUCCESSFULLY, 200, true, PagesSectionResource::make($section));
    }

    public function destroy(Section $section)
    {
        $section->delete();
        return $this->apiResponse(SECTION_DELETED_SUCCESSFULLY, 200, true);
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'sections'   => 'required|array',
            'sections.*' => 'required|integer|distinct|exists:sections,id',
        ]);

        try {
            Section::setNewOrder($request->sections);
            $this->flushTag(FrontendResource::CONTENT_PAGES->value);
            return $this->apiResponse(SECTIONS_REORDERED_SUCCESSFULLY, 200, true);
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function toggleStatus(Section $section)
    {
        $section->is_active = !$section->is_active;
        $section->save();
        $section->loadMissing('sectionType.settings');
        return $this->apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, PagesSectionResource::make($section));
    }

    public function getTypeSection()
    {
        $types = Section::get()->pluck('type')->unique();
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $types);
    }
}