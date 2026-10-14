<?php


namespace Marvel\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Repositories\RefundReasonRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\RefundReasonCreateRequest;
use Marvel\Http\Requests\RefundReasonUpdateRequest;
use Prettus\Validator\Exceptions\ValidatorException;


/**
 * @OA\Tag(name="Refund Reasons", description="Public and shop-specific refund reason management")
 *
 * @OA\Schema(
 *     schema="RefundReason",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Damaged Item"),
 *     @OA\Property(property="slug", type="string", example="damaged-item"),
 *     @OA\Property(property="language", type="string", example="en"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class RefundReasonController extends CoreController
{
    public $repository;

    public function __construct(RefundReasonRepository $repository)
    {
        $this->repository = $repository;
    }
    /**
     * @OA\Get(
     *     path="/refund-reasons",
     *     operationId="getRefundReasons",
     *     tags={"Refund Reasons"},
     *     summary="List Refund Reasons",
     *     description="Retrieve a paginated list of refund reasons.",
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Response(
     *         response=200,
     *         description="Refund reasons retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/RefundReason")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ?   $request->limit : 15;
        return $this->fetchRefundReasons($request)->paginate($limit);
    }

    public function fetchRefundReasons(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;
        return $this->repository->where('language', $language);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param RefundReasonCreateRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(RefundReasonCreateRequest $request)
    {
        try {
            if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
                return $this->repository->storeRefundReason($request);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Get(
     *     path="/refund-reasons/{id_or_slug}",
     *     operationId="getRefundReason",
     *     tags={"Refund Reasons"},
     *     summary="Get Single Refund Reason",
     *     description="Retrieve details of a refund reason by its ID or slug.",
     *     @OA\Parameter(name="id_or_slug", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Response(
     *         response=200,
     *         description="Refund reason details retrieved successfully",
     *         @OA\JsonContent(ref="#/components/schemas/RefundReason")
     *     ),
     *     @OA\Response(response=404, description="Refund reason not found")
     * )
     */
    public function show(Request $request, $params)
    {

        try {
            $language = $request->language ?? DEFAULT_LANGUAGE;
            if (is_numeric($params)) {
                $params = (int) $params;
                return $this->repository->where('id', $params)->firstOrFail();
            }
            return $this->repository->where('slug', $params)->firstOrFail();
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param RefundReasonUpdateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(RefundReasonUpdateRequest $request, $id)
    {
        try {
            $request['id'] = $id;
            return $this->refundReasonUpdate($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    public function refundReasonUpdate(Request $request)
    {
        try {
            $item = $this->repository->findOrFail($request->id);
            return $this->repository->updateRefundReason($request, $item);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        $request->merge(['id' => $id]);
        return $this->deleteRefundReason($request);
    }

    public function deleteRefundReason(Request $request)
    {
        try {
            $refundReason = $this->repository->findOrFail($request->id);
            $refundReason->delete();
            return $refundReason;
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }
}
