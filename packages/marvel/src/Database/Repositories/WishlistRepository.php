<?php

namespace Marvel\Database\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Variation;
use Marvel\Database\Models\Wishlist;
use Marvel\Exceptions\MarvelException;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WishlistRepository extends BaseRepository
{
    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    /**
     * @var array[]
     */
    protected $dataArray = [
        'user_id',
        'product_id',
        'product_variant_id'
    ];

    /**
     * Configure the Model
     **/
    public function model()
    {
        return Wishlist::class;
    }

    /**
     * @param $request
     * @return LengthAwarePaginator|JsonResponse|Collection|mixed
     */
    public function storeWishlist($request)
    {
        $user_id = $request->user()->id;
        $wishlist = $this->findUserWishlistItem($user_id, $request['product_id'], $request['product_variant_id'] ?? null);
        if (empty($wishlist)) {
            $request['user_id'] = $user_id;
            $wishlistInput = $request->only($this->dataArray);
            return $this->create($wishlistInput);
        }
        throw new HttpException(400, ALREADY_ADDED_TO_WISHLIST_FOR_THIS_PRODUCT);
    }

    /**
     * @param $request
     * @return LengthAwarePaginator|JsonResponse|Collection|mixed
     */
    public function toggleWishlist($request)
    {
        $user_id = $request->user()->id;
        $wishlist = $this->findUserWishlistItem($user_id, $request['product_id'], $request['product_variant_id'] ?? null);
        if (empty($wishlist)) {
            $request['user_id'] = $user_id;
            $wishlistInput = $request->only($this->dataArray);
            $this->create($wishlistInput);
            return true;
        }
        $this->delete($wishlist->id);
        return false;
    }

    /**
     * Find a single wishlist row for the given user, product and (optional) variant.
     * Uses an explicit `whereNull` for simple products because `findOneWhere`
     * produces `WHERE product_variant_id = NULL`, which never matches in MySQL.
     *
     * @param int   $user_id
     * @param mixed $product_id
     * @param mixed $product_variant_id
     * @return mixed
     */
    private function findUserWishlistItem(int $user_id, $product_id, $product_variant_id)
    {
        $query = Wishlist::query()
            ->where('user_id', $user_id)
            ->where('product_id', $product_id);

        if (!empty($product_variant_id)) {
            $query->where('product_variant_id', $product_variant_id);
        } else {
            $query->whereNull('product_variant_id');
        }

        return $query->first();
    }
}
