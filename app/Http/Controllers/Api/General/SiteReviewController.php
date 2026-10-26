<?php

namespace App\Http\Controllers\Api\General;

use App\Enums\FrontendResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\SiteReview\CreateSiteReviewRequest;
use App\Http\Resources\SiteReview\SiteReviewResource;
use App\Services\SiteReview\SiteReviewService;
use App\Traits\HasCache;
use Marvel\Traits\ApiResponse;

class SiteReviewController extends Controller
{
    use ApiResponse, HasCache;

    private $siteReviewService;

    public function __construct(SiteReviewService $siteReviewService)
    {
        $this->siteReviewService = $siteReviewService;
    }

    public function index()
    {
        // Lazy closure: the approved-reviews query (and resource build) must run
        // only on cache miss; previously the query executed on every request.
        $reviewsCache = $this->remember(
            FrontendResource::SITE_REVIEWS->value,
            md5(request()->fullUrl()),
            fn () => SiteReviewResource::collection($this->siteReviewService->getApprovedReviews())
        );

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $reviewsCache);
    }

    public function store(CreateSiteReviewRequest $request)
    {
        $review = $this->siteReviewService->createReview($request->user(), $request->validated());

        return $this->apiResponse(SITE_REVIEW_CREATED_SUCCESSFULLY, 201, true, SiteReviewResource::make($review));
    }
}
