<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CouponDistributionRunStatus;
use App\Enums\CouponDistributionTriggerType;
use App\Http\Controllers\Controller;
use App\Models\CouponDistributionRun;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\NonDistributableCouponException;
use Illuminate\Http\Request;
use Marvel\Database\Models\Coupon;
use Marvel\Traits\ApiResponse;

/**
 * Manual distribution + operational run visibility.
 *
 * Permission: `update-coupon` (see config coupon-distribution.admin_permission)
 * — explicit and documented. Double-submit safe: an already pending/running
 * run for the same targeting version returns 409 with the live run instead
 * of launching a duplicate mass distribution. No per-user PII is exposed:
 * runs carry counters only.
 */
class CouponDistributionAdminController extends Controller
{
    use ApiResponse;

    public function __construct()
    {
        $this->middleware(['auth:sanctum']);
    }

    private function authorizeAdmin(Request $request): void
    {
        $permission = (string) config('coupon-distribution.admin_permission', 'update-coupon');
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        try {
            if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo($permission)) {
                return;
            }

            if (method_exists($user, 'can') && $user->can($permission)) {
                return;
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Fail-closed but auditable: operators must distinguish
            // misconfiguration/outage from forbidden access.
            \Illuminate\Support\Facades\Log::warning('coupon.admin.auth_failed', [
                'permission' => $permission,
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        abort(403, "Forbidden. Missing required permission: {$permission}.");
    }

    /**
     * POST /api/v1/admin/coupons/{id}/distribute
     * Body: {trigger?: manual, audience_cap?: int}
     */
    public function distribute(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'trigger' => ['sometimes', 'string', 'in:manual'],
            'audience_cap' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('coupon-distribution.max_audience_cap', 100000)],
        ]);

        try {
            $coupon = Coupon::with('targeting')->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->apiResponse(COUPON_NOT_FOUND, 404, false);
        }

        try {
            $result = app(DistributionService::class)->startDistribution(
                $coupon,
                CouponDistributionTriggerType::MANUAL,
                'manual',
                'admin:'.$request->user()->getKey(),
                (int) ($request->input('audience_cap') ?? config('coupon-distribution.default_audience_cap', 10000)),
            );
        } catch (NonDistributableCouponException $e) {
            return $this->apiResponse(COUPON_NOT_ELIGIBLE ?? SOMETHING_WENT_WRONG, 422, false, [
                'reason' => 'not_distributable',
            ]);
        }

        /** @var CouponDistributionRun $run */
        $run = $result['run'];

        if (! $result['created']
            && in_array($run->status, [CouponDistributionRunStatus::PENDING, CouponDistributionRunStatus::RUNNING], true)
        ) {
            return $this->apiResponse(
                'A distribution run is already in progress for this targeting version.',
                409,
                false,
                ['reason' => 'already_running', 'run' => $this->presentRun($run)]
            );
        }

        return $this->apiResponse(
            'Distribution run accepted.',
            202,
            true,
            ['run' => $this->presentRun($run->fresh())]
        );
    }

    /**
     * GET /api/v1/admin/coupons/{id}/distributions
     */
    public function index(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        try {
            Coupon::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->apiResponse(COUPON_NOT_FOUND, 404, false);
        }

        $runs = CouponDistributionRun::query()
            ->where('coupon_id', $id)
            ->orderByDesc('id')
            ->paginate(15);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'data' => collect($runs->items())->map(fn ($run) => $this->presentRun($run))->all(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'per_page' => $runs->perPage(),
                'total' => $runs->total(),
                'last_page' => $runs->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/coupons/{id}/distributions/{runId}
     */
    public function show(Request $request, int $id, int $runId)
    {
        $this->authorizeAdmin($request);

        try {
            $run = CouponDistributionRun::query()
                ->where('coupon_id', $id)
                ->findOrFail($runId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->apiResponse(COUPON_NOT_FOUND, 404, false);
        }

        $breakdown = $run->recipients()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'run' => $this->presentRun($run),
            'recipient_breakdown' => $breakdown,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRun(CouponDistributionRun $run): array
    {
        $status = $run->status;

        return [
            'id' => $run->getKey(),
            'coupon_id' => $run->coupon_id,
            'trigger_type' => $run->trigger_type,
            'tree_hash' => $run->tree_hash,
            'status' => $status instanceof \BackedEnum ? $status->value : (string) $status,
            'candidate_count' => $run->candidate_count,
            'eligible_count' => $run->eligible_count,
            'not_eligible_count' => $run->not_eligible_count,
            'notified_count' => $run->notified_count,
            'failed_count' => $run->failed_count,
            'duplicate_skipped_count' => $run->duplicate_skipped_count,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
