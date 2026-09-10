<?php

namespace Marvel\Http\Controllers;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Repositories\CouponAssignmentRepository;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelBadRequestException;
use Marvel\Http\Requests\CouponAssignmentRequest;
use Marvel\Http\Requests\UpdateCouponAssignmentRequest;
use Marvel\Http\Resources\CouponAssignmentResource;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CouponAssignmentController extends CoreController
{
    use ApiResponse;

    public function __construct(
        protected CouponAssignmentRepository $repository,
    ) {
        $this->middleware("permission:" . Permission::VIEW_COUPON_ASSIGNMENTS, ["only" => ["index", "show"]]);
        $this->middleware("permission:" . Permission::CREATE_COUPON_ASSIGNMENT, ["only" => ["store"]]);
        $this->middleware("permission:" . Permission::UPDATE_COUPON_ASSIGNMENT, ["only" => ["update"]]);
        $this->middleware("permission:" . Permission::DELETE_COUPON_ASSIGNMENT, ["only" => ["destroy"]]);
    }

    public function index(Request $request, $couponId): JsonResponse
    {
        try {
            $limit = $request->limit ?? 15;
            $assignments = $this->repository->listByCoupon((int) $couponId, (int) $limit);
            return $this->apiResponse(COUPON_ASSIGNMENTS_FETCHED_SUCCESSFULLY, 200, true, [
                "data" => CouponAssignmentResource::collection($assignments),
                "current_page" => $assignments->currentPage(),
                "from" => $assignments->firstItem(),
                "last_page" => $assignments->lastPage(),
                "per_page" => $assignments->perPage(),
                "to" => $assignments->lastItem(),
                "total" => $assignments->total(),
            ]);
        } catch (Exception $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }
    }

    public function store(CouponAssignmentRequest $request, $couponId): JsonResponse
    {
        try {
            $assignment = $this->repository->assignCoupon((int) $couponId, $request->validated());
            return $this->apiResponse(COUPON_ASSIGNED_SUCCESSFULLY, 201, true, CouponAssignmentResource::make($assignment->load('user')));
        } catch (MarvelBadRequestException $e) {
            return $this->apiResponse($e->getMessage(), 409, false);
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        } catch (Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 400, false);
        }
    }

    public function show(Request $request, $couponId, $assignmentId): JsonResponse
    {
        try {
            $assignment = $this->repository->findAssignment((int) $couponId, (int) $assignmentId);
            return $this->apiResponse(COUPON_ASSIGNMENTS_FETCHED_SUCCESSFULLY, 200, true, CouponAssignmentResource::make($assignment));
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        } catch (Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 400, false);
        }
    }

    public function update(UpdateCouponAssignmentRequest $request, $couponId, $assignmentId): JsonResponse
    {
        try {
            $assignment = $this->repository->updateAssignment((int) $couponId, (int) $assignmentId, $request->validated());
            return $this->apiResponse(COUPON_ASSIGNMENT_UPDATED_SUCCESSFULLY, 200, true, CouponAssignmentResource::make($assignment));
        } catch (MarvelBadRequestException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        } catch (Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 400, false);
        }
    }

    public function destroy(Request $request, $couponId, $assignmentId): JsonResponse
    {
        try {
            $this->repository->removeAssignment((int) $couponId, (int) $assignmentId);
            return $this->apiResponse(COUPON_ASSIGNMENT_DELETED_SUCCESSFULLY, 200, true);
        } catch (MarvelBadRequestException $e) {
            return $this->apiResponse($e->getMessage(), 409, false);
        } catch (ModelNotFoundException $e) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        } catch (Exception $e) {
            return $this->apiResponse(SOMETHING_WENT_WRONG, 400, false);
        }
    }
}
