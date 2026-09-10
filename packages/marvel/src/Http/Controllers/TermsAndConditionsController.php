<?php

namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\TermsAndConditions;
use Marvel\Database\Repositories\TermsAndConditionsRepository;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\CreateTermsAndConditionsRequest;
use Marvel\Http\Requests\UpdateTermsAndConditionsRequest;
use Prettus\Validator\Exceptions\ValidatorException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Marvel\Http\Resources\TermsConditionResource;
use Marvel\Traits\ApiResponse;


/**
 * @OA\Tag(name="Terms & Conditions", description="Public and shop-specific terms and conditions management")
 *
 * @OA\Schema(
 *     schema="TermsAndConditions",
 *     type="object",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="title", type="string", example="Privacy Policy"),
 *     @OA\Property(property="slug", type="string", example="privacy-policy"),
 *     @OA\Property(property="description", type="string", example="Our detailed privacy policy..."),
 *     @OA\Property(property="shop_id", type="integer", nullable=true),
 *     @OA\Property(property="is_approved", type="boolean", example=true),
 *     @OA\Property(property="language", type="string", example="en"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class TermsAndConditionsController extends CoreController
{
    use ApiResponse;
    public $repository;

    public function __construct(TermsAndConditionsRepository $repository)
    {
        $this->repository = $repository;
    }


    /**
     * @OA\Get(
     *     path="/terms-and-conditions",
     *     operationId="getTermsAndConditions",
     *     tags={"Terms & Conditions"},
     *     summary="List Terms & Conditions",
     *     description="Retrieve a paginated list of terms and conditions. Public users see only approved ones.",
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=10)),
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Parameter(name="shop_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Terms & conditions retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/TermsAndConditions")),
     *             @OA\Property(property="total", type="integer")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        // $language = $request->language ?? DEFAULT_LANGUAGE;
        $termsAndConditions = $this->fetchTermsAndConditions($request)->paginate($limit)->withQueryString();
        $termsData = TermsConditionResource::collection($termsAndConditions)->response()->getData(true);
        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            "data" => $termsData['data'] ?? [],
            "page" => $termsData['meta']['current_page'] ?? 0,
            "current_page" => $termsData['meta']['current_page'] ?? 0,
            "from" => $termsData['meta']['from'] ?? 0,
            "to" => $termsData['meta']['to'] ?? 0,
            "last_page" => $termsData['meta']['last_page'] ?? 0,
            "path" => $termsData['meta']['path'] ?? "",
            "per_page" => $termsData['meta']['per_page'] ?? 0,
            "total" => $termsData['meta']['total'] ?? 0,
            "next_page_url" => $termsData['links']['next'] ?? "",
            "prev_page_url" => $termsData['links']['prev'] ?? "",
            "last_page_url" => $termsData['links']['last'] ?? "",
            "first_page_url" => $termsData['links']['first'] ?? "",
        ]);
    }

    public function fetchTermsAndConditions(Request $request)
    {

        try {
            $user = $request->user();
            $language = $request->language ?? DEFAULT_LANGUAGE;

            // if statement is for role base authorized scenerio
            // else statment is for global viewers level guest scenerio

            if (isset($user)) {
                switch ($user) {
                    case $user->hasRole(Role::SUPER_ADMIN):
                        return $this->repository->with('shop')->where('language', $language);
                        break;

                    case $user->hasPermissionTo(Permission::STORE_OWNER):
                        if ($this->repository->hasPermission($user, $request->shop_id)) {
                            return $this->repository->with('shop')->where('shop_id', '=', $request->shop_id)->where('language', $language);
                        } else {
                            return $this->repository->with('shop')->where('user_id', '=', $user->id)->where('language', $language)->whereIn('shop_id', $user->shops->pluck('id'));
                        }
                        break;

                    case $user->hasPermissionTo(Permission::STAFF):
                        if ($this->repository->hasPermission($user, $request->shop_id)) {
                            return $this->repository->with('shop')->where('shop_id', '=', $request->shop_id)->where('language', $language);
                        }
                        break;

                    default:
                        return $this->repository->with('shop')->where('language', $language)->where('is_approved', '=', true);
                        break;
                }
            } else {
                if ($request->shop_id) {
                    return $this->repository->with('shop')->where('shop_id', '=', $request->shop_id)->where('is_approved', '=', true)->where('language', $language);
                } else {
                    return $this->repository->with('shop')->where('is_approved', '=', true)->where('language', $language);
                }
            }
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }

    /**
     * Store a newly created termsAndConditions in storage.
     *
     * @param CreateTermsAndConditionsRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(CreateTermsAndConditionsRequest $request)
    {
        try {
            return $this->repository->storeTermsAndConditions($request);
            // return $this->repository->create($validatedData);
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE, $e->getMessage());
        }
    }

    /**
     * @OA\Get(
     *     path="/terms-and-conditions/{slug}",
     *     operationId="getTermsAndConditionsBySlug",
     *     tags={"Terms & Conditions"},
     *     summary="Get Single Terms & Conditions",
     *     description="Retrieve details of a terms and conditions by its slug.",
     *     @OA\Parameter(name="slug", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="language", in="query", required=false, @OA\Schema(type="string", default="en")),
     *     @OA\Response(
     *         response=200,
     *         description="Terms & conditions details retrieved",
     *         @OA\JsonContent(ref="#/components/schemas/TermsAndConditions")
     *     ),
     *     @OA\Response(response=404, description="Terms & conditions not found")
     * )
     */
    public function show(Request $request, $slug)
    {
        try {
            $language = $request->language ?? DEFAULT_LANGUAGE;
            $termsAndCondition = $this->repository->with('shop')->where('language', $language)->where('slug', '=', $slug)->first();
            return new TermsConditionResource($termsAndCondition);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND, $e->getMessage());
        }
    }

    /**
     * Update the specified terms and conditions
     *
     * @param UpdateTermsAndConditionsRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateTermsAndConditionsRequest $request, $id)
    {
        try {
            $request["id"] = $id;
            return $this->updateTermsAndConditions($request);
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE, $e->getMessage());
        }
    }

    /**
     * updateTermsAndConditions
     *
     * @param  UpdateTermsAndConditionsRequest $request
     * @return void
     */
    public function updateTermsAndConditions(UpdateTermsAndConditionsRequest $request)
    {
        $termsAndConditions = $this->repository->findOrFail($request['id']);
        return $this->repository->updateTermsAndConditions($request, $termsAndConditions);
    }

    /**
     * Remove the specified terms and conditions
     *
     * @param int $id
     * @param Request $request
     * @return JsonResponse
     */
    public function destroy($id, Request $request)
    {
        $request->merge(['id' => $id]);
        return $this->deleteTermsConditions($request);
    }

    public function deleteTermsConditions(Request $request)
    {
        try {
            $user = $request->user();
            if ($user && ($user->hasRole(Role::SUPER_ADMIN) || $user->hasPermissionTo(Permission::STORE_OWNER) || $user->hasPermissionTo(Permission::STAFF))) {
                return $this->repository->findOrFail($request->id)->delete();
            }
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND, $e->getMessage());
        }
    }

    /**
     * approveTerm
     *
     * @param  Request $request
     * @return void
     */
    public function approveTerm(Request $request)
    {
        try {
            if (!$request->user()->hasRole(Role::SUPER_ADMIN)) {
                throw new MarvelException(NOT_AUTHORIZED);
            }
            $id = $request->id;
            try {
                $term = $this->repository->findOrFail($id);
            } catch (\Exception $e) {
                throw new ModelNotFoundException(NOT_FOUND);
            }
            $term->is_approved = true;
            $term->save();
            return $term;
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * disApproveTerm
     *
     * @param  Request $request
     * @return void
     */
    public function disApproveTerm(Request $request)
    {
        try {
            if (!$request->user()->hasRole(Role::SUPER_ADMIN)) {
                throw new MarvelException(NOT_AUTHORIZED);
            }
            $id = $request->id;
            try {
                $term = $this->repository->findOrFail($id);
            } catch (\Exception $e) {
                throw new ModelNotFoundException(NOT_FOUND);
            }

            $term->is_approved = false;
            $term->save();
            return $term;
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
}
