<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Services\General\SectionTypeService;
use App\Traits\HasCache;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Database\Models\SectionType;
use Marvel\Http\Requests\StoreSectionTypeRequest;
use Marvel\Http\Requests\UpdateSectionTypeRequest;
use Marvel\Traits\ApiResponse;

class SectionTypeController extends CoreController
{
    use ApiResponse, HasCache;

    public function __construct(
        private SectionTypeService $sectionTypeService
    ) {
        $this->middleware('permission:' . Permission::VIEW_SECTION_TYPES)->only(['index', 'show', 'settings', 'byType']);
        $this->middleware('permission:' . Permission::CREATE_SECTION_TYPES)->only('store');
        $this->middleware('permission:' . Permission::UPDATE_SECTION_TYPES)->only(['update', 'updateSettings']);
        $this->middleware('permission:' . Permission::DELETE_SECTION_TYPES)->only('destroy');
    }

    public function index()
    {
        $types = $this->sectionTypeService->getAll();
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $types);
    }

    public function store(StoreSectionTypeRequest $request)
    {
        try {
            $type = $this->sectionTypeService->createType($request->validated());
            return $this->apiResponse(TYPE_CREATED_SUCCESSFULLY, 200, true, $type);
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    public function show(SectionType $sectionType)
    {
        $grouped = $this->sectionTypeService->getSettingsGrouped($sectionType->type);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $grouped);
    }

    public function update(UpdateSectionTypeRequest $request, SectionType $sectionType)
    {
        $type = $this->sectionTypeService->updateType($sectionType->id, $request->validated());
        return $this->apiResponse(TYPE_UPDATED_SUCCESSFULLY, 200, true, $type);
    }

    public function destroy(SectionType $sectionType)
    {
        $this->sectionTypeService->deleteType($sectionType->id);
        return $this->apiResponse(TYPE_DELETED_SUCCESSFULLY, 200, true);
    }

    public function settings(string $type)
    {
        $sectionType = $this->sectionTypeService->getByType($type);
        if (!$sectionType) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        $grouped = $this->sectionTypeService->getSettingsGrouped($type);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $grouped);
    }

    public function updateSettings(Request $request, string $type)
    {
        $request->validate([
            'front' => 'nullable|array',
            'back' => 'nullable|array',
        ]);

        $sectionType = $this->sectionTypeService->getByType($type);
        if (!$sectionType) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        try {
            $this->sectionTypeService->upsertSettings($sectionType->id, $request->only(['front', 'back']));
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }

        // invalidate the frontend content pages cache. Required in addition to the
        // SectionTypeSettingObserver because the bulk delete path uses a query
        // builder which does not fire Eloquent model events.
        $this->flushTag(FrontendResource::CONTENT_PAGES->value);

        $grouped = $this->sectionTypeService->getSettingsGrouped($type);
        return $this->apiResponse(SETTINGS_UPDATED_SUCCESSFULLY, 200, true, $grouped);
    }

    public function byType(string $type)
    {
        $sectionType = $this->sectionTypeService->getByType($type);
        if (!$sectionType) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
        $grouped = $this->sectionTypeService->getSettingsGrouped($type);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $grouped);
    }
}
