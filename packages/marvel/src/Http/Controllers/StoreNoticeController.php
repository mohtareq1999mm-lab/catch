<?php

namespace Marvel\Http\Controllers;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Marvel\Database\Models\StoreNotice;
use Marvel\Database\Repositories\StoreNoticeReadRepository;
use Marvel\Database\Repositories\StoreNoticeRepository;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Enums\StoreNoticeType;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\StoreNoticeRequest;
use Marvel\Http\Requests\StoreNoticeUpdateRequest;
use Marvel\Http\Resources\GetSingleStoreNoticeResource;
use Marvel\Http\Resources\StoreNoticeResource;
use Marvel\Traits\ApiResponse;
use Prettus\Validator\Exceptions\ValidatorException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @OA\Schema(
 *     schema="StoreNotice",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="type", type="string", example="all_vendor"),
 *     @OA\Property(property="priority", type="string", example="high"),
 *     @OA\Property(property="notice", type="string", example="System Maintenance"),
 *     @OA\Property(property="description", type="string", example="We will be down for 2 hours..."),
 *     @OA\Property(property="effective_from", type="string", format="date-time"),
 *     @OA\Property(property="expired_at", type="string", format="date-time"),
 *     @OA\Property(property="is_read", type="boolean", example=false)
 * )
 */
class StoreNoticeController extends CoreController
{
    use ApiResponse;
    public $repository;

    private $repositoryPivot;

    public function __construct(StoreNoticeRepository $repository, StoreNoticeReadRepository $repositoryPivot)
    {
        $this->repository = $repository;
        $this->repositoryPivot = $repositoryPivot;
    }


    /**
     * @OA\Get(
     *     path="/store-notices",
     *     operationId="getStoreNotices",
     *     tags={"Store Notices"},
     *     summary="List store notices",
     *     description="Retrieve a paginated list of store notices for the authenticated user.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of notices retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/StoreNotice")),
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->limit ? $request->limit : 15;
            $storeNotices = $this->fetchStoreNotices($request)->paginate($limit);
            $noticeData = StoreNoticeResource::collection($storeNotices)->response()->getData(true);
            return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
                "data" => $noticeData['data'] ?? [],
                "page" => $noticeData['meta']['current_page'] ?? 0,
                "current_page" => $noticeData['meta']['current_page'] ?? 0,
                "from" => $noticeData['meta']['from'] ?? 0,
                "to" => $noticeData['meta']['to'] ?? 0,
                "last_page" => $noticeData['meta']['last_page'] ?? 0,
                "path" => $noticeData['meta']['path'] ?? "",
                "per_page" => $noticeData['meta']['per_page'] ?? 0,
                "total" => $noticeData['meta']['total'] ?? 0,
                "next_page_url" => $noticeData['links']['next'] ?? "",
                "prev_page_url" => $noticeData['links']['prev'] ?? "",
                "last_page_url" => $noticeData['links']['last'] ?? "",
                "first_page_url" => $noticeData['links']['first'] ?? "",
            ]);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $th->getMessage());
        }
    }

    /**
     * @param Request $request
     * @return StoreNoticeRepository
     * @throws MarvelException
     */
    public function fetchStoreNotices(Request $request)
    {
        return $this->repository->whereNotNull('id');
    }

    /**
     * @OA\Post(
     *     path="/store-notices",
     *     operationId="storeStoreNotice",
     *     tags={"Store Notices"},
     *     summary="Create a new store notice",
     *     description="Post a notice for shops or users. Restricted to Super Admin or Shop Owner.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"notice", "description", "priority", "type"},
     *             @OA\Property(property="notice", type="string", example="Holiday Closure"),
     *             @OA\Property(property="description", type="string", example="Shop will be closed..."),
     *             @OA\Property(property="priority", type="string", enum={"high", "medium", "low"}),
     *             @OA\Property(property="type", type="string"),
     *             @OA\Property(property="received_by", type="array", @OA\Items(type="integer"), description="Array of shop or user IDs")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Notice created successfully", @OA\JsonContent(ref="#/components/schemas/StoreNotice")),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function store(StoreNoticeRequest $request)
    {
        try {
            if ($request->user()->hasRole(Role::SUPER_ADMIN) || $this->repository->hasPermission($request->user(), $request->received_by[0] ?? 0)) {
                return $this->repository->saveStoreNotice($request);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Get(
     *     path="/store-notices/getStoreNoticeType",
     *     operationId="getStoreNoticeTypes",
     *     tags={"Store Notices"},
     *     summary="Get available notice types",
     *     description="Retrieve list of valid notice types (e.g., all_shop, all_vendor).",
     *     security={{"sanctum": {}}},
     *     @OA\Response(response=200, description="List of types retrieved successfully")
     * )
     */
    public function getStoreNoticeType(Request $request)
    {
        return $this->repository->fetchStoreNoticeType($request);
    }

    /**
     * @OA\Get(
     *     path="/store-notices/getUsersToNotify",
     *     operationId="getUsersToNotify",
     *     tags={"Store Notices"},
     *     summary="Get candidates for notice recipients",
     *     description="Retrieve users or shops that can receive notices based on current user scope.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="type", in="query", required=true),
     *     @OA\Response(response=200, description="List retrieved successfully")
     * )
     */
    public function getUsersToNotify(Request $request)
    {
        $typeArr = array(StoreNoticeType::ALL_SHOP, StoreNoticeType::ALL_VENDOR);
        if (in_array($request->type, $typeArr)) {
            throw new HttpException(400, ACTION_NOT_VALID);
        }
        return $this->repository->fetchUserToSendNotification($request);
    }

    /**
     * @OA\Get(
     *     path="/store-notices/{id}",
     *     operationId="getStoreNoticeById",
     *     tags={"Store Notices"},
     *     summary="Get single store notice",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Notice found", @OA\JsonContent(ref="#/components/schemas/StoreNotice")),
     *     @OA\Response(response=404, description="Notice not found")
     * )
     */
    public function show(Request $request, $id)
    {
        try {
            $storeNotice = $this->repository->findOrFail($id);
            // return $storeNotice;
            return new GetSingleStoreNoticeResource($storeNotice);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * @OA\Put(
     *     path="/store-notices/{id}",
     *     operationId="updateStoreNotice",
     *     tags={"Store Notices"},
     *     summary="Update store notice",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="notice", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="priority", type="string")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Notice updated", @OA\JsonContent(ref="#/components/schemas/StoreNotice"))
     * )
     */
    public function update(StoreNoticeUpdateRequest $request, $id)
    {
        try {
            $request['id'] = $id;
            return $this->updateStoreNotice($request);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }


    /**
     * @OA\Delete(
     *     path="/store-notices/{id}",
     *     operationId="deleteStoreNotice",
     *     tags={"Store Notices"},
     *     summary="Delete store notice",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Notice deleted successfully")
     * )
     */
    public function destroy(Request $request, $id)
    {

        try {
            $request['id'] = $id ?? 0;
            return $this->deleteStoreNotice($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }

    /**
     * Remove the specified resource from storage.
     * @param Request $request
     * @return mixed
     * @throws MarvelException
     */
    public function deleteStoreNotice(Request $request)
    {
        try {
            $id = $request->id;
            return $this->repository->findOrFail($id)->forceDelete();
        } catch (Exception $e) {
            throw new HttpException(400, COULD_NOT_DELETE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Post(
     *     path="/store-notices/read",
     *     operationId="markNoticeAsRead",
     *     tags={"Store Notices"},
     *     summary="Mark a single notice as read",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             required={"id"},
     *             @OA\Property(property="id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Marked as read successfully")
     * )
     */
    public function readNotice(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|exists:Marvel\Database\Models\StoreNotice,id'
            ]);
            return $this->repositoryPivot->readSingleNotice($request);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }


    /**
     * @OA\Post(
     *     path="/store-notices/read-all",
     *     operationId="markAllNoticesAsRead",
     *     tags={"Store Notices"},
     *     summary="Mark multiple notices as read",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             required={"notices"},
     *             @OA\Property(property="notices", type="array", @OA\Items(type="integer", example=1))
     *         )
     *     ),
     *     @OA\Response(response=200, description="Notices marked as read")
     * )
     */
    public function readAllNotice(Request $request)
    {
        try {
            $request->validate([
                'notices' => 'required|array|min:1',
                'notices.*' => 'exists:Marvel\Database\Models\StoreNotice,id',
            ]);
            return $this->repositoryPivot->readAllNotice($request);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
}
