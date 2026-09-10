<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Repositories\BannerRepository;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\BannerCreateRequest;
use Marvel\Http\Requests\BannerUpdateRequest;
use Marvel\Http\Resources\BannerResource;
use Marvel\Traits\ApiResponse;

class BannerController extends CoreController
{
    use ApiResponse;
    public $repository;
    public function __construct(BannerRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware("permission:".Permission::VIEW_BANNERS)->only(["index","show"]);
        $this->middleware("permission:".Permission::CREATE_BANNERS)->only("store");
        $this->middleware("permission:".Permission::UPDATE_BANNERS)->only(["update", "changeStatus", "reorder"]);
        $this->middleware("permission:".Permission::DELETE_BANNERS)->only("destroy");
    }

    public function index()
    {
        $banners = $this->repository->getBanners();
        $bannerData = BannerResource::collection($banners)->response()->getData(true);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $bannerData['data'] ?? [],
            "page" => $bannerData['meta']['current_page'] ?? 0,
            "current_page" => $bannerData['meta']['current_page'] ?? 0,
            "from" => $bannerData['meta']['from'] ?? 0,
            "to" => $bannerData['meta']['to'] ?? 0,
            "last_page" => $bannerData['meta']['last_page'] ?? 0,
            "path" => $bannerData['meta']['path'] ?? "",
            "per_page" => $bannerData['meta']['per_page'] ?? 0,
            "total" => $bannerData['meta']['total'] ?? 0,
            "next_page_url" => $bannerData['links']['next'] ?? "",
            "prev_page_url" => $bannerData['links']['prev'] ?? "",
            "last_page_url" => $bannerData['links']['last'] ?? "",
            "first_page_url" => $bannerData['links']['first'] ?? "",
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(BannerCreateRequest $request)
    {
        try{
            $banner = $this->repository->createBanner($request);
            $banner->load('products');
            return $this->apiResponse(BANNER_CREATED_SUCCESSFULLY,200, true, BannerResource::make($banner));
        }catch(\Exception $e){
            return $this->apiResponse(SOMETHING_WENT_WRONG,500, false);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try{
            $banner = $this->repository->findOrFail($id);
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY,200, true, BannerResource::make($banner));
        }catch(\Exception $e){
            return $this->apiResponse(NOT_FOUND,404, false);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BannerUpdateRequest $request, string $id)
    {
        try{
            $banner = $this->repository->updateBanner($request, $id);
            $banner->load('products');
            return $this->apiResponse(BANNER_UPDATED_SUCCESSFULLY,200, true, BannerResource::make($banner));
        }catch(\Exception $e){
            return $this->apiResponse(NOT_FOUND,404, false);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try{
            $banner = $this->repository->findOrFail($id);
            $banner->delete();
            return $this->apiResponse(BANNER_DELETED_SUCCESSFULLY,200, true);
        }catch(\Exception $e){
            return $this->apiResponse(NOT_FOUND,404, false, null);
        }
    }

    public function changeStatus(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:banners,id',
        ]);
        $banner = $this->repository->changeStatus($request->id);
        if(!$banner){
            return $this->apiResponse(SOMETHING_WENT_WRONG,500, false);
        }
        return $this->apiResponse(BANNER_STATUS_CHANGED,200, true, BannerResource::make($banner));
    }


    public function reorder(Request $request)
    {
        $request->validate([
            'banners' => 'required|array',
            'banners.*' => 'required|exists:banners,id',
        ]);
        try {
            $this->repository->reorder($request->banners);

            return $this->apiResponse(BANNERS_REORDERED_SUCCESSFULLY, 200, true);
        } catch (\Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 500, false);
        }
    }
}