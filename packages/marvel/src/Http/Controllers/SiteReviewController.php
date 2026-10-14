<?php

namespace Marvel\Http\Controllers;

use App\Enums\FrontendResource;
use App\Http\Resources\SiteReview\AdminSiteReviewResource;
use App\Services\SiteReview\SiteReviewService;
use App\Traits\HasCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Traits\ApiResponse;

class SiteReviewController extends CoreController
{
    use ApiResponse, HasCache;

    private $siteReviewService;

    public function __construct(SiteReviewService $siteReviewService)
    {
        $this->siteReviewService = $siteReviewService;
        $this->middleware('permission:' . Permission::VIEW_SITE_REVIEWS, ['only' => ['index', 'show']]);
        $this->middleware('permission:' . Permission::APPROVE_SITE_REVIEWS, ['only' => ['approve']]);
        $this->middleware('permission:' . Permission::REJECT_SITE_REVIEWS, ['only' => ['reject']]);
    }

    public function index(Request $request)
    {
        $limit = max(1, min((int) $request->query('limit', 15), 100));
        $status = $request->query('status');

        $reviews = $this->siteReviewService->getAllReviews($status, $limit);
        $reviews->withQueryString();
        $reviewData = AdminSiteReviewResource::collection($reviews)->response()->getData(true);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'data' => $reviewData['data'] ?? [],
            'page' => $reviewData['meta']['current_page'] ?? 0,
            'current_page' => $reviewData['meta']['current_page'] ?? 0,
            'from' => $reviewData['meta']['from'] ?? 0,
            'to' => $reviewData['meta']['to'] ?? 0,
            'last_page' => $reviewData['meta']['last_page'] ?? 0,
            'path' => $reviewData['meta']['path'] ?? '',
            'per_page' => $reviewData['meta']['per_page'] ?? 0,
            'total' => $reviewData['meta']['total'] ?? 0,
            'next_page_url' => $reviewData['links']['next'] ?? '',
            'prev_page_url' => $reviewData['links']['prev'] ?? '',
            'last_page_url' => $reviewData['links']['last'] ?? '',
            'first_page_url' => $reviewData['links']['first'] ?? '',
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $review = $this->siteReviewService->findReview($id);

        if (!$review) {
            return $this->apiResponse(SITE_REVIEW_NOT_FOUND, 404, false);
        }

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, AdminSiteReviewResource::make($review));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $review = $this->siteReviewService->approveReview($id, $request->user());

        if (!$review) {
            return $this->apiResponse(SITE_REVIEW_NOT_FOUND, 404, false);
        }

        $this->flushTag(FrontendResource::SITE_REVIEWS->value);

        return $this->apiResponse(SITE_REVIEW_APPROVED_SUCCESSFULLY, 200, true, AdminSiteReviewResource::make($review));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $review = $this->siteReviewService->rejectReview($id, $request->user());

        if (!$review) {
            return $this->apiResponse(SITE_REVIEW_NOT_FOUND, 404, false);
        }

        $this->flushTag(FrontendResource::SITE_REVIEWS->value);

        return $this->apiResponse(SITE_REVIEW_REJECTED_SUCCESSFULLY, 200, true, AdminSiteReviewResource::make($review));
    }
}
