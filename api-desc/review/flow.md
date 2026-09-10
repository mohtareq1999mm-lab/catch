# Request Flows — Review Module

## Flow 1: List Reviews

```
Client → GET /api/v1/reviews?product_id=10&limit=15&page=1
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    ReviewController@index(Request)
         ↓
    $request->validate(['product_id' => 'required|integer|exists:products,id'])
         ↓
    Fail? → 422 with field errors
         ↓
    $this->repository->where('product_id', $request['product_id'])->paginate($limit)
         ↓
    ReviewResource::collection($data) → transform each review
         ↓
    Return: { status:200, message, success:true, data[] }
```

## Flow 2: Create Review

```
Client → POST /api/v1/reviews (JSON)
         ↓
    [auth:sanctum] middleware
         ↓
    [throttle:content] middleware → 5 requests/min per user
         ↓
    ReviewCreateRequest → validation rules:
      - product_id: required, exists:products,id
      - comment: required, string
      - rating: required, integer, min:1, max:5
         ↓
    Fail? → 422 with field errors
         ↓
    ReviewController@store()
         ↓
    ReviewRepository::storeReview($request)
         ↓
    DB::beginTransaction()
      ├─ Extract data: $request->only(['product_id', 'user_id', 'comment', 'rating'])
      ├─ Set user_id from auth()->id()
      ├─ Review::create($data)
      └─ If images[] → uploadImages('reviews', 'reviews')
         ↓
    DB::commit()
         ↓
    ReviewResource::make($review)
         ↓
    Return: { status:200, message, success:true, data }

    On exception (duplicate):
      → MarvelException(ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT) → 400
    On DB failure:
      → DB::rollBack()
      → HttpException(400, SOMETHING_WENT_WRONG)
```

## Flow 3: Show Review

```
Client → GET /api/v1/reviews/1
         ↓
    [auth:sanctum] middleware
         ↓
    ReviewController@show($id)
         ↓
    ReviewRepository::findOrFail($id)
         ↓
    Found? → ReviewResource::make($review) → 200
    Not found? → MarvelException(NOT_FOUND) → 404
```

## Flow 4: Update Review

```
Client → PUT /api/v1/reviews/1 (JSON)
         ↓
    [auth:sanctum] middleware
         ↓
    [throttle:content] middleware → 5 requests/min per user
         ↓
    ReviewUpdateRequest → validation rules:
      - comment: required, string
      - rating: required, integer, min:1, max:5
         ↓
    Fail? → 422
         ↓
    ReviewController@update($request, $id)
         ↓
    $request->merge(['id' => $id])
         ↓
    updateReview($request) [private]
      → ReviewRepository::updateReview($request, $id)
         ↓
    DB::beginTransaction()
      ├─ Review::findOrFail($id)
      ├─ $review->update($data)
      └─ If images[] → updateImages('reviews', 'reviews')
         ↓
    DB::commit()
         ↓
    ReviewResource::make($review)
         ↓
    Return: { status:200, message, success:true, data }

    On failure:
      → DB::rollBack()
      → HttpException(400, SOMETHING_WENT_WRONG) → 400
```

## Flow 5: Delete Review

```
Client → DELETE /api/v1/reviews/1
         ↓
    [auth:sanctum] middleware
         ↓
    [permission:delete-reviews] middleware
         ↓
    ReviewController@destroy($id)
         ↓
    ReviewRepository::findOrFail($id)
         ↓
    $review->delete()  → sets deleted_at (soft delete)
         ↓
    Return: { status:200, message, success:true }

    Not found? → MarvelException(NOT_FOUND) → 404
```

## Flow 6: Toggle Approve Review

```
Client → PATCH /api/v1/reviews/1/toggle-approve
         ↓
    [auth:sanctum] middleware
         ↓
    [permission:approve-reviews] middleware
         ↓
    ReviewController@toggleApproveReview($id)
         ↓
    ReviewRepository::toggleApprove($id)
         ↓
    Review::findOrFail($id)
         ↓
    $review->approved = !$review->approved
    $review->save()
         ↓
    ReviewResource::make($review)
         ↓
    Return: { status:200, message, success:true, data }

    Not found? → MarvelException(NOT_FOUND) → 404
```
