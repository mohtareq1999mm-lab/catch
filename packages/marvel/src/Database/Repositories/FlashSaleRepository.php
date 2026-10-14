<?php


namespace Marvel\Database\Repositories;

use Exception;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Traits\MediaManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FlashSaleRepository extends BaseRepository
{
    use MediaManager;

    /**
     * @var array
     */
    protected $fieldSearchable = [
        'title' => 'like',
        //        'language',
    ];

    /**
     * @var array
     */
    protected $dataArray = [
        'title',
        'slug',
        'description',
        'start_date',
        'end_date',
        'type',
        'status',
        'max_discount_amount',
        'discount',
    ];


    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return FlashSale::class;
    }

    public function modelQuery()
    {
        return FlashSale::query();
    }


    /**
     * storeFlashSale
     *
     * @param  mixed $request
     * @return void
     */
    public function storeFlashSale($request): FlashSale
    {
        try {
            // only admin can create flash deals
            DB::beginTransaction();
            $request['slug'] = $this->makeSlug($request);
            $data = $request->only($this->dataArray);
            $flash_sale = $this->create($data);

            if ($request->hasFile('image-desktop')) {
                if (!$this->uploadSingleImage($request, 'image-desktop', $flash_sale, 'flash-sales-desktop', 'flashSales')) {
                    throw new HttpException(422, 'Flash sale image upload failed, please check the file format or size.');
                }
            }

            if ($request->hasFile('image-mobile')) {
                if (!$this->uploadSingleImage($request, 'image-mobile', $flash_sale, 'flash-sales-mobile', 'flashSales')) {
                    throw new HttpException(422, 'Flash sale image upload failed, please check the file format or size.');
                }
            }

            if ($request->has('products')) {
                $flash_sale->products()->sync($request->products);
                $this->setProductInFlashSale($request->products);
            }

            DB::commit();
            return $flash_sale;
        } catch (Exception $th) {
            DB::rollBack();
            Log::error('FlashSale store failed: ' . $th->getMessage(), ['trace' => $th->getTraceAsString()]);
            throw new HttpException(500, SOMETHING_WENT_WRONG);
        }
    }


    /**
     * updateFlashSale
     *
     * @param  mixed $request
     * @param  mixed $id
     * @return void
     */
    public function updateFlashSale(Request $request, $id)
    {
        try {
            // only admin can update flash deals
            DB::beginTransaction();
            $flash_sale = $this->findOrFail($id);
            $request['slug'] = $this->makeSlug($request, 'slug', $flash_sale->id);
            $flash_sale->update($request->except('image-desktop', 'image-mobile'));

            if ($request->hasFile('image-desktop')) {
                if (!$this->updateSingleImage($request, 'image-desktop', $flash_sale, 'flash-sales-desktop', 'flashSales')) {
                    throw new HttpException(422, 'Flash sale image upload failed, please check the file format or size.');
                }
            }

            if ($request->hasFile('image-mobile')) {
                if (!$this->updateSingleImage($request, 'image-mobile', $flash_sale, 'flash-sales-mobile', 'flashSales')) {
                    throw new HttpException(422, 'Flash sale image upload failed, please check the file format or size.');
                }
            }

            if ($request->has('products')) {
                $oldProductIds = $flash_sale->products()->pluck('product_id')->toArray();
                $products = array_filter(array_map('intval', (array) $request->products), fn($id) => $id > 0);
                $flash_sale->products()->sync($products);
                $this->unsetProductFromFlashSale($oldProductIds, $products);
                $this->setProductInFlashSale($products);
            }

            $this->updateFlashSaleProductPrices($flash_sale);
            DB::commit();
            return $flash_sale;
        } catch (Exception $e) {
            DB::rollBack();
            throw new HttpException(500, SOMETHING_WENT_WRONG);
        }
    }

    /**
     * setProductInFlashSale
     *
     * @param  array $product_ids
     * @return void
     */
    public function setProductInFlashSale($product_ids)
    {
        foreach ($product_ids as $product_id) {
            $product = Product::findOrFail($product_id);
            $product->has_flash_sale = true;
            $product->save();
        }
    }


    /**
     * unsetProductFromFlashSale
     *
     * @param  array $previous_list
     * @param  array $new_list
     * @return void
     */
    public function unsetProductFromFlashSale($previous_list, $new_list)
    {
        $final_list = array_diff($previous_list, $new_list);

        if (isset($final_list)) {
            foreach ($final_list as $key => $product_id) {
                $product = Product::findOrFail($product_id);
                $product->has_flash_sale = false;
                $product->save();
            }
        }
    }

    public function reorder(array $flashSales)
    {
        try {
            $this->setNewOrder($flashSales);
        } catch (\Exception $e) {
            throw new HttpException(500, $e->getMessage());
        }
    }

    private function updateFlashSaleProductPrices(FlashSale $flashSale)
    {
        $flashSale->load('products');
        $now = now();
        $isActive = $flashSale->status
            && $flashSale->start_date
            && $flashSale->end_date
            && $now->between($flashSale->start_date, $flashSale->end_date);

        foreach ($flashSale->products as $product) {
            if (!$isActive) {
                $product->price_after_flash_sale = null;
                $product->save();
                continue;
            }

            $basePrice = $product->getDiscountedPrice() ?? $product->price;
            $product->price_after_flash_sale = $flashSale->calcPrice($basePrice);
            $product->save();
        }
    }
}