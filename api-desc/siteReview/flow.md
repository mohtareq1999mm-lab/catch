# Request Flows — Site Reviews Module

## Flow 1: List Approved Reviews (Public)

```
Client → GET /api/v1/general/site-reviews
         ↓
    [throttle:public-api] middleware (route group)
         ↓
    General\SiteReviewController@index()
         ↓
    SiteReviewService::getApprovedReviews()
      SiteReview::with('user')
        ->where('status', SiteReviewStatus::APPROVED)
        ->latest()
        ->get()
         ↓
    remember('site_reviews', md5(fullUrl),
             SiteReviewResource::collection($reviews), 4h)
      Cache hit?  → return cached collection
      Cache miss? → store collection under tag 'site_reviews'
         ↓
    apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $cached)
         ↓
    Return: { status:200, message, success:true, data[] }
```

## Flow 2: Submit a Review (Customer)

```
Client → POST /api/v1/general/site-reviews
         (JSON: { rating, title?, comment })
         ↓
    [auth:sanctum] middleware → authenticate token
    [throttle:authenticated] middleware (route group)
         ↓
    CreateSiteReviewRequest → validation
      - rating: required|integer|min:1|max:5
      - title:  nullable|string|max:191
      - comment: required|string|max:2000
         ↓
    Fail? → 422 { message, status:false, errors }
         ↓
    General\SiteReviewController@store(CreateSiteReviewRequest)
         ↓
    SiteReviewService::createReview($request->user(), $request->validated())
      SiteReview::create([
        'user_id'      => $customer->id,
        'rating'       => (int) rating,
        'title'        => title ?? null,
        'comment'      => comment,
        'status'       => SiteReviewStatus::PENDING,   // forced
        'moderated_by' => null,                        // forced
        'moderated_at' => null,                        // forced
      ])
      (customer-supplied status/moderated_by/moderated_at ignored)
         ↓
    SiteReviewResource::make($review)
         ↓
    Return: { status:201, message: SITE_REVIEW_CREATED_SUCCESSFULLY,
              success:true, data }
```

## Flow 3: List Reviews (Admin)

```
Client → GET /api/v1/site-reviews?status=pending&limit=20&page=2
         ↓
    [auth:sanctum] → [throttle:admin] (route group)
    [permission:view-site-reviews] (controller)
         ↓
    SiteReviewController@index(Request)
      limit  = min(max((int)?limit, 1), 100)   // default 15
      status = ?status
         ↓
    SiteReviewService::getAllReviews(status, limit)
      SiteReview::with(['user', 'moderator'])
        ->when(status is valid enum or 'all' → where('status', status))
        ->latest()
        ->paginate(limit)
         ↓
    AdminSiteReviewResource::collection($paginator)
      ->response()->getData(true)   // flatten pagination meta
         ↓
    apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, {
      data[], page, current_page, from, to, last_page, path,
      per_page, total, next_page_url, prev_page_url, last_page_url,
      first_page_url
    })
```

## Flow 4: View Review Detail (Admin)

```
Client → GET /api/v1/site-reviews/1
         ↓
    [auth:sanctum] → [throttle:admin]
    [permission:view-site-reviews]
    Route constraint: ->whereNumber('id')   // 'abc' → 404
         ↓
    SiteReviewController@show(int $id)
         ↓
    SiteReviewService::findReview($id)
      SiteReview::with(['user', 'moderator'])->find($id)
         ↓
    Found? → AdminSiteReviewResource::make → 200
    Not found? → apiResponse(SITE_REVIEW_NOT_FOUND, 404, false)
```

## Flow 5: Approve a Review (Admin)

```
Client → PATCH /api/v1/site-reviews/1/approve
         ↓
    [auth:sanctum] → [throttle:admin]
    [permission:approve-site-reviews]
    Route constraint: ->whereNumber('id')
         ↓
    SiteReviewController@approve(Request, int $id)
         ↓
    SiteReviewService::approveReview($id, $request->user())
      moderate() in DB::transaction:
        1. $review = SiteReview::find($id)
        2. !$review OR $review->status !== PENDING → null
        3. update(status=APPROVED, moderated_by=admin.id, moderated_at=now)
        4. $review->load(['user', 'moderator'])
         ↓
    null? → apiResponse(SITE_REVIEW_NOT_FOUND, 404, false)
         ↓
    flushTag(FrontendResource::SITE_REVIEWS->value)   // public cache refresh
         ↓
    Return: { status:200, message: SITE_REVIEW_APPROVED_SUCCESSFULLY,
              success:true, data: AdminSiteReviewResource }
```

## Flow 6: Reject a Review (Admin)

```
Client → PATCH /api/v1/site-reviews/1/reject
         ↓
    [auth:sanctum] → [throttle:admin]
    [permission:reject-site-reviews]
    Route constraint: ->whereNumber('id')
         ↓
    SiteReviewController@reject(Request, int $id)
         ↓
    SiteReviewService::rejectReview($id, $request->user())
      moderate() in DB::transaction:
        1. $review = SiteReview::find($id)
        2. !$review OR $review->status !== PENDING → null
        3. update(status=REJECTED, moderated_by=admin.id, moderated_at=now)
        4. $review->load(['user', 'moderator'])
         ↓
    null? → apiResponse(SITE_REVIEW_NOT_FOUND, 404, false)
         ↓
    flushTag(FrontendResource::SITE_REVIEWS->value)
         ↓
    Return: { status:200, message: SITE_REVIEW_REJECTED_SUCCESSFULLY,
              success:true, data: AdminSiteReviewResource }
```

## Status Lifecycle

```
         ┌─────────── admin approves (DB tx) ───────────┐
         │                                             ▼
  customer submits ──► [pending]                 [approved] ◄─ public
         │                                             ▲
         └─────────── admin rejects (DB tx) ───────────┘
                                             ▼
                                       [rejected] (hidden)

  Transitions allowed: pending → approved | rejected
  Reverts (approved↔rejected, or re-moderation) are NOT allowed → 404
```
