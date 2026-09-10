<?php


namespace Marvel\Database\Repositories;


use App\Events\QuestionAnswered;
use App\Events\ReviewApproved;
use App\Events\ReviewCreated;
use App\Events\ReviewRejected;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Review;
use Marvel\Exceptions\MarvelException;
use Marvel\Traits\MediaManager;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Prettus\Validator\Exceptions\ValidatorException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReviewRepository extends BaseRepository
{
    use MediaManager;
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'rating',
        'product_id',
    ];

    /**
     * @var array[]
     */
    protected $dataArray = [
        'product_id',
        'user_id',
        'comment',
        'rating',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
        }
    }


    /**
     * Configure the Model
     **/
    public function model()
    {
        return Review::class;
    }


    /**
     * @param $request
     * @return LengthAwarePaginator|JsonResponse|Collection|mixed
     */
    public function storeReview($request)
    {
        // add logic to verified purchase and only one rating on each product
        try {
            DB::beginTransaction();
            $reviewInput = $request->only($this->dataArray);
            $reviewInput['user_id'] = auth()->id();
            $review = $this->create($reviewInput);
            if ($request->has('images')) {
                if (!$this->uploadImages($request, 'images', $review, 'reviews', 'reviews')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }
            // event(new ReviewCreated($review));
            DB::commit();
            return $review;
        } catch (Exception $e) {
            DB::rollBack();
            throw new HttpException(400, SOMETHING_WENT_WRONG);
        }
    }

    public function updateReview($request, $id)
    {
        try {
            DB::beginTransaction();
            $review = $this->findOrFail($id);
            $review->update($request->only($this->dataArray));
            if ($request->has('images')) {
                if (!$this->updateImages($request, 'images', $review, 'reviews', 'reviews')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }
            DB::commit();
            return $review;
        } catch (Exception $e) {
            DB::rollBack();
            throw new HttpException(400, SOMETHING_WENT_WRONG);
        }
    }

    public function toggleApprove($id)
    {
        try {
            $review = $this->findOrFail($id);
            $wasApproved = (bool) $review->approved;
            $review->approved = !$review->approved;
            $review->save();

            if (!$wasApproved && $review->approved) {
                event(new ReviewApproved($review));
            } elseif ($wasApproved && !$review->approved) {
                event(new ReviewRejected($review));
            }

            return $review;
        } catch (Exception $e) {
            throw new HttpException(400, SOMETHING_WENT_WRONG);
        }
    }
}
