<?php

namespace Marvel\Http\Controllers;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Repositories\RefundPolicyRepository;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Exceptions\MarvelException;
use Marvel\Exceptions\MarvelNotFoundException;
use Marvel\Http\Requests\StoreRefundPolicyRequest;
use Marvel\Http\Requests\UpdateRefundPolicyRequest;
use Marvel\Http\Resources\RefundPolicyResource;
use Marvel\Traits\ApiResponse;


/**
 * @OA\Tag(name="Refund Policies", description="Public and shop-specific refund policy management")
 *
 * @OA\Schema(
 *     schema="RefundPolicy",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="title", type="string", example="30-Day Return Policy"),
 *     @OA\Property(property="slug", type="string", example="30-day-return-policy"),
 *     @OA\Property(property="description", type="string", example="Detailed policy text..."),
 *     @OA\Property(property="amount", type="number", example=0.00),
 *     @OA\Property(property="status", type="string", example="approved"),
 *     @OA\Property(property="language", type="string", example="en"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class RefundPolicyController extends CoreController
{
    use ApiResponse;

    public function __construct(private readonly RefundPolicyRepository $repository)
    {
    }


    /**
     * @OA\Get(
     *     path="/refund-policies",
     *     operationId="getRefundPolicies",
     *     tags={"Refund Policies"},
     *     summary="List Refund Policies",
     *     description="Retrieve a paginated list of refund policies.",
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=15)),
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Response(
     *         response=200,
     *         description="Refund policies retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/RefundPolicy")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ?   $request->limit : 15;
        $refundPolicies =  $this->fetchRefundPolicies($request)->paginate($limit);
        $policyData = RefundPolicyResource::collection($refundPolicies)->response()->getData(true);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $policyData['data'] ?? [],
            "page" => $policyData['meta']['current_page'] ?? 0,
            "current_page" => $policyData['meta']['current_page'] ?? 0,
            "from" => $policyData['meta']['from'] ?? 0,
            "to" => $policyData['meta']['to'] ?? 0,
            "last_page" => $policyData['meta']['last_page'] ?? 0,
            "path" => $policyData['meta']['path'] ?? "",
            "per_page" => $policyData['meta']['per_page'] ?? 0,
            "total" => $policyData['meta']['total'] ?? 0,
            "next_page_url" => $policyData['links']['next'] ?? "",
            "prev_page_url" => $policyData['links']['prev'] ?? "",
            "last_page_url" => $policyData['links']['last'] ?? "",
            "first_page_url" => $policyData['links']['first'] ?? "",
        ]);
    }

    public function fetchRefundPolicies(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;
        return $this->repository->where('language', $language);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreRefundPolicyRequest $request
     * @return mixed
     */
    public function store(StoreRefundPolicyRequest $request)
    {
        try {
            if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
                return $this->repository->storeRefundPolicy($request);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Get(
     *     path="/refund-policies/{slug}",
     *     operationId="getRefundPolicyBySlug",
     *     tags={"Refund Policies"},
     *     summary="Get Single Refund Policy",
     *     description="Retrieve details of a refund policy by its slug.",
     *     @OA\Parameter(name="slug", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Response(
     *         response=200,
     *         description="Refund policy details retrieved successfuly",
     *         @OA\JsonContent(ref="#/components/schemas/RefundPolicy")
     *     ),
     *     @OA\Response(response=404, description="Refund policy not found")
     * )
     */
    public function show(Request $request, $slug)
    {
        try {
            $request->merge(['slug' => $slug]);
            return $this->fetchRefundPolicy($request);
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param $slug
     * @return JsonResponse
     */
    public function fetchRefundPolicy(Request $request)
    {
        $slug = $request->slug;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        try {
            return $this->repository->findRefundPolicy($slug, $language);
        } catch (Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateRefundPolicyRequest $request
     * @param int $id
     * @return array
     * @throws Marvel\Exceptions\MarvelException
     */
    public function update(UpdateRefundPolicyRequest $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            return $this->updateRefundPolicy($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function updateRefundPolicy(Request $request)
    {
        $slug = $request->id ?? $request->slug;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
            try {
                $refundPolicy = $this->repository->findRefundPolicy($slug, $language);
                return $this->repository->updateRefundPolicy($request, $refundPolicy);
            } catch (Exception $e) {
                throw new MarvelNotFoundException(NOT_FOUND);
            }
        }
        throw new AuthorizationException(NOT_AUTHORIZED);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy($id, Request $request)
    {
        try {
            $request->merge(['id' => $id]);
            return $this->deleteRefundPolicy($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }

    public function deleteRefundPolicy(Request $request)
    {
        $slug = $request->id ?? $request->slug;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        if ($request->user()->hasRole(Role::SUPER_ADMIN)) {
            $refundPolicy = $this->repository->findRefundPolicy($slug, $language);
            $refundPolicy->delete();
            return $refundPolicy;
        }
        throw new AuthorizationException(NOT_AUTHORIZED);
    }
}
