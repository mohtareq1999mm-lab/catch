<?php


namespace Marvel\Database\Repositories;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Category;
use Marvel\Traits\MediaManager;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Symfony\Component\HttpKernel\Exception\HttpException;



class CategoryRepository extends BaseRepository
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
        'parent_id',
        'is_featured',
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
        return Category::class;
    }

    public function saveCategory(Request $request)
    {
        try {
            DB::beginTransaction();
            $request['slug'] = $this->makeSlug($request);
            $data = $request->only($this->dataArray);

            $category = $this->create($data);

            if ($request->has('products')) {
                $category->products()->sync($request->products);
            }

            if ($request->has('image-desktop')) {
                if (!$this->uploadSingleImage($request, 'image-desktop', $category, 'categories-desktop', 'categories')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }
            if ($request->has('image-mobile')) {
                if (!$this->uploadSingleImage($request, 'image-mobile', $category, 'categories-mobile', 'categories')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }


            DB::commit();
            return $category;
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new HttpException(500, $e->getMessage());
        }
    }


    public function updateCategory($request, $category)
    {
        try {
            DB::beginTransaction();

            $data = $request->only($this->dataArray);
            if (isset($data['name'])) {
                $data['slug'] = $this->makeSlug($request, 'slug', $category->id);
            }
            $category->update($data);
            if ($request->has('image-desktop')) {
                if (!$this->updateSingleImage($request, 'image-desktop', $category, 'categories-desktop', 'categories')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }
            if ($request->has('image-mobile')) {
                if (!$this->updateSingleImage($request, 'image-mobile', $category, 'categories-mobile', 'categories')) {
                    throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
                }
            }
            if ($request->has('products')) {
                $category->products()->sync($request->products);
            }
            DB::commit();
            return $this->findOrFail($category->id);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new HttpException(500, $e->getMessage());
        }
    }
}
