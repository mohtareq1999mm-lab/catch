<?php


namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\AbusiveReport;
use Marvel\Database\Repositories\AbusiveReportRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\AbusiveReportAcceptOrRejectRequest;
use Marvel\Http\Requests\AbusiveReportCreateRequest;
use Prettus\Validator\Exceptions\ValidatorException;


class AbusiveReportController extends CoreController
{
    public $repository;

    public function __construct(AbusiveReportRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @OA\Get(
     *     path="/abusive_reports",
     *     operationId="listAbuseReports",
     *     tags={"Content Moderation"},
     *     summary="List Abuse Reports",
     *     description="Get paginated list of all abuse reports. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="limit", in="query", description="Items per page", @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Reports retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN")
     * )
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        return $this->repository->paginate($limit);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param AbusiveReportCreateRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(AbusiveReportCreateRequest $request)
    {

        try {
            $model_id = $request['model_id'];
            $model_type = $request['model_type'];
            $model_name = "Marvel\\Database\\Models\\{$model_type}";
            $model = $model_name::findOrFail($model_id);
            $request['user_id'] = $request->user()->id;
            return $this->repository->storeAbusiveReport($request, $model);
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * @OA\Get(
     *     path="/abusive_reports/{id}",
     *     operationId="getAbuseReport",
     *     tags={"Content Moderation"},
     *     summary="Get Abuse Report Details",
     *     description="Get a single abuse report by ID. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Report ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Report retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Report not found")
     * )
     */
    public function show($id)
    {
        try {
            return $this->repository->findOrFail($id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Delete(
     *     path="/abusive_reports/{id}",
     *     operationId="deleteAbuseReport",
     *     tags={"Content Moderation"},
     *     summary="Delete Abuse Report",
     *     description="Delete an abuse report. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="Report ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Report deleted successfully"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="Report not found")
     * )
     */
    public function destroy($id)
    {
        try {
            return $this->repository->findOrFail($id)->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Post(
     *     path="/abusive_reports/accept",
     *     operationId="acceptAbuseReport",
     *     tags={"Content Moderation"},
     *     summary="Accept Abuse Report",
     *     description="Accept an abuse report and delete the reported content. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"model_id", "model_type"},
     *             @OA\Property(property="model_id", type="integer", example=5),
     *             @OA\Property(property="model_type", type="string", example="Marvel\\Database\\Models\\Review")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Report accepted, content deleted"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="Content not found")
     * )
     */
    public function accept(AbusiveReportAcceptOrRejectRequest $request)
    {
        try {
            $model_id = $request['model_id'];
            $model_type = $request['model_type'];
            $model = $model_type::findOrFail($model_id);
            return $model->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * @OA\Post(
     *     path="/abusive_reports/reject",
     *     operationId="rejectAbuseReport",
     *     tags={"Content Moderation"},
     *     summary="Reject Abuse Report",
     *     description="Reject an abuse report and keep the content. Deletes all reports for this content. Requires SUPER_ADMIN permission.",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"model_id", "model_type"},
     *             @OA\Property(property="model_id", type="integer", example=5),
     *             @OA\Property(property="model_type", type="string", example="Marvel\\Database\\Models\\Review")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Report rejected, content kept"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - requires SUPER_ADMIN"),
     *     @OA\Response(response=404, description="Content not found")
     * )
     */
    public function reject(AbusiveReportAcceptOrRejectRequest $request)
    {
        $model_id = $request['model_id'];
        $model_type = str_replace("\\", "\\", $request['model_type']);
        try {
            $this->repository->deleteWhere([
                'model_id' => $model_id,
                'model_type' => $model_type
            ]);
            return $model_type::findOrFail($model_id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Display a listing of the resource for authenticated user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function myReports(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;

        return $this->repository->where('user_id', auth()->user()->id)->paginate($limit);
    }
}
