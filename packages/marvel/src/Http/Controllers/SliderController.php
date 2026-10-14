<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Marvel\Database\Repositories\SliderRepository;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\SliderCreateRequest;
use Marvel\Http\Requests\SliderUpdateRequest;
use Marvel\Http\Resources\SliderResource;
use Marvel\Traits\ApiResponse;
use Illuminate\Http\Request;

class SliderController extends CoreController
{
    use ApiResponse, HasCache;
    public $repository;
    public function __construct(SliderRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware("permission:" . Permission::VIEW_SLIDER)->only(["index", "show"]);
        $this->middleware("permission:" . Permission::CREATE_SLIDER)->only("store");
        $this->middleware("permission:" . Permission::UPDATE_SLIDER)->only(["update", "changeStatus", "reorder"]);
        $this->middleware("permission:" . Permission::DELETE_SLIDER)->only("destroy");
    }

    public function index(Request $request)
    {
        $sliders = $this->repository->getSliders($request);
        $data = SliderResource::collection($sliders)->response()->getData(true);
        $dataCache = $this->remember(FrontendResource::SLIDERS->value, md5($request->fullUrl()), $data);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" =>  $dataCache['data'] ?? [],
            "page" =>  $dataCache['meta']['current_page'] ?? 0,
            "current_page" =>  $dataCache['meta']['current_page'] ?? 0,
            "from" =>  $dataCache['meta']['from'] ?? 0,
            "to" =>  $dataCache['meta']['to'] ?? 0,
            "last_page" =>  $dataCache['meta']['last_page'] ?? 0,
            "path" =>  $dataCache['meta']['path'] ?? "",
            "per_page" =>  $dataCache['meta']['per_page'] ?? 0,
            "total" =>  $dataCache['meta']['total'] ?? 0,
            "next_page_url" =>  $dataCache['links']['next'] ?? "",
            "prev_page_url" =>  $dataCache['links']['prev'] ?? "",
            "last_page_url" =>  $dataCache['links']['last'] ?? "",
            "first_page_url" =>  $dataCache['links']['first'] ?? "",
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SliderCreateRequest $request)
    {
        try {
            $slider = $this->repository->createSlider($request);
            $slider->load('products');
            $this->flushTag(frontendResource::SLIDERS->value);
            return $this->apiResponse(SLIDER_CREATED_SUCCESSFULLY, 200, true, SliderResource::make($slider));
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $slider = $this->repository->findOrFail($id);
            $slider->load('products');
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SliderResource::make($slider));
        } catch (\Exception $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SliderUpdateRequest $request, string $id)
    {
        try {
            $slider = $this->repository->updateSlider($request, $id);
            $slider->load('products');
            $this->flushTag(frontendResource::SLIDERS->value);
            return $this->apiResponse(SLIDER_UPDATED_SUCCESSFULLY, 200, true, SliderResource::make($slider));
        } catch (\Exception $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $slider = $this->repository->findOrFail($id);
            $slider->delete();
            $this->flushTag(frontendResource::SLIDERS->value);
            return $this->apiResponse(SLIDER_DELETED_SUCCESSFULLY, 200, true);
        } catch (\Exception $e) {
            return $this->apiResponse(NOT_FOUND, 404, false, null);
        }
    }

    public function changeStatus(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:sliders,id',
        ]);
        $slider = $this->repository->changeStatus($request->id);
        if (!$slider) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
        $slider->load('products');
        $this->flushTag(frontendResource::SLIDERS->value);
        return $this->apiResponse(SLIDER_STATUS_CHANGED, 200, true, SliderResource::make($slider));
    }


    public function reorder(Request $request)
    {
        $request->validate([
            'sliders' => 'required|array',
            'sliders.*' => 'required|exists:sliders,id',
        ]);
        try {
            $this->repository->reorder($request->sliders);
            $this->flushTag(frontendResource::SLIDERS->value);
            return $this->apiResponse(SLIDERS_REORDERED_SUCCESSFULLY, 200, true);
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }
}
