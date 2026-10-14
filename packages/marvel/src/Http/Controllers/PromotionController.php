<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Traits\HasCache;
use Illuminate\Http\Request;
use Marvel\Exceptions\MarvelException;
use Marvel\Database\Repositories\PromotionRepository;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\PromotionRequest;
use Marvel\Http\Requests\UpdatePromotionRequest;
use Marvel\Http\Resources\PromotionResource;
use Marvel\Traits\ApiResponse;
use Throwable;

class PromotionController extends CoreController
{
    use ApiResponse , HasCache;

    public $repository;

    public function __construct(PromotionRepository $repository)
    {
        $this->repository = $repository;
        $this->middleware("permission:" . Permission::VIEW_PROMOTION, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_PROMOTION, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_PROMOTION, ["only" => ["update"]]);
        $this->middleware("permission:" . Permission::DELETE_PROMOTION, ["only" => ["destroy"]]);
    }

    public function index(Request $request)
    {
        $limit = $request->limit ?? 15;
        $query = $this->repository;

        if ($search = $request->query('search')) {
            $query = $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('code', 'LIKE', "%{$search}%")
                  ->orWhere('type', 'LIKE', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $query = $query->where('status', $request->query('status') === 'true');
        }

        if ($request->has('type')) {
            $query = $query->where('type', $request->query('type'));
        }

        if ($request->has('type_amount')) {
            $query = $query->where('type_amount', $request->query('type_amount'));
        }

        $orderBy = $request->query('order_by', 'created_at');
        $sort = $request->query('sort', 'desc');
        $query = $query->orderBy($orderBy, $sort);

        $promotions = $query->paginate($limit)->withQueryString();
        $promotionData = PromotionResource::collection($promotions)->response()->getData(true);
        $promotionDataCache = $this->remember(FrontendResource::PROMOTIONS->value, md5($request->fullUrl()), $promotionData);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $promotionDataCache['data'] ?? [],
            "page" => $promotionDataCache['meta']['current_page'] ?? 0,
            "current_page" => $promotionDataCache['meta']['current_page'] ?? 0,
            "from" => $promotionDataCache['meta']['from'] ?? 0,
            "to" => $promotionDataCache['meta']['to'] ?? 0,
            "last_page" => $promotionDataCache['meta']['last_page'] ?? 0,
            "path" => $promotionDataCache['meta']['path'] ?? "",
            "per_page" => $promotionDataCache['meta']['per_page'] ?? 0,
            "total" => $promotionDataCache['meta']['total'] ?? 0,
            "next_page_url" => $promotionDataCache['links']['next'] ?? "",
            "prev_page_url" => $promotionDataCache['links']['prev'] ?? "",
            "last_page_url" => $promotionDataCache['links']['last'] ?? "",
            "first_page_url" => $promotionDataCache['links']['first'] ?? "",
        ]);
    }

    public function store(PromotionRequest $request)
    {
        try {
            $promotion = $this->repository->storePromotion($request);
            $this->flushTag(FrontendResource::PROMOTIONS->value);
            return $this->apiResponse(CREATED_PROMOTION_SUCCESSFULLY, 201, true, PromotionResource::make($promotion));
        } catch (MarvelException $e) {
            return $this->apiResponse(COULD_NOT_CREATE_THE_RESOURCE, 400, false);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $promotion = $this->repository->findOrFail($id);
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, PromotionResource::make($promotion));
        } catch (Throwable $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
    }

    public function update(UpdatePromotionRequest $request, $id)
    {
        try {
            $promotion = $this->repository->updatePromotion($id, $request);
            $this->flushTag(FrontendResource::PROMOTIONS->value);
            return $this->apiResponse(UPDATED_PROMOTION_SUCCESSFULLY, 200, true, PromotionResource::make($promotion));
        } catch (MarvelException $e) {
            return $this->apiResponse(COULD_NOT_UPDATE_THE_RESOURCE, 400, false);
        }
    }

    public function destroy($id)
    {
        try {
            $promotion = $this->repository->findOrFail($id);
            $promotion->delete();
            $this->flushTag(FrontendResource::PROMOTIONS->value);
            return $this->apiResponse(DELETED_PROMOTION_SUCCESSFULLY, 200, true);
        } catch (Throwable $e) {
            return $this->apiResponse(COULD_NOT_DELETE_THE_RESOURCE, 400, false);
        }
    }
}
