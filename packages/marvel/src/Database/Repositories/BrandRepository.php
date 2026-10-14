<?php

namespace Marvel\Database\Repositories;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Brand;
use Marvel\Traits\MediaManager;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BrandRepository extends BaseRepository
{
    use MediaManager;

    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name' => 'like',
    ];

    protected $dataArray = [
        'name',
        'slug',
        'details',
        'status',
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
        return Brand::class;
    }

    public function saveBrand(Request $request)
    {
        try {
            DB::beginTransaction();
            $data = $request->only($this->dataArray);
            $data['slug'] = $this->makeSlug($request);

            $brand = $this->create($data);

            if ($request->has('products')) {
                $products = array_filter(array_map('intval', (array) $request->products), fn($id) => $id > 0);
                $brand->products()->sync($products);
            }

            if ($request->has('image-desktop')) {
                if (!$this->uploadSingleImage($request, 'image-desktop', $brand, 'brands-desktop', 'brands')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }
            if ($request->has('image-mobile')) {
                if (!$this->uploadSingleImage($request, 'image-mobile', $brand, 'brands-mobile', 'brands')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }

            DB::commit();
            return $brand;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Brand save failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new HttpException(500, COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    public function updateBrand($request, $brand)
    {
        try {
            DB::beginTransaction();
            $data = $request->only($this->dataArray);
            if (isset($data['name'])) {
                $data['slug'] = $this->makeSlug($request, 'slug', $brand->id);
            }
            $brand->update($data);
            if ($request->has('products')) {
                $products = array_filter(array_map('intval', (array) $request->products), fn($id) => $id > 0);
                $brand->products()->sync($products);
            }
            if ($request->has('image-desktop')) {
                if (!$this->updateSingleImage($request, 'image-desktop', $brand, 'brands-desktop', 'brands')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }
            if ($request->has('image-mobile')) {
                if (!$this->updateSingleImage($request, 'image-mobile', $brand, 'brands-mobile', 'brands')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }
            DB::commit();
            return $this->findOrFail($brand->id);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Brand update failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw new HttpException(500, COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function reorder(array $brands)
    {
        try {
            DB::beginTransaction();
            $this->setNewOrder($brands);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new HttpException(500, $e->getMessage());
        }
    }
}
