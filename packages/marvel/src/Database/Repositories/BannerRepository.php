<?php


namespace Marvel\Database\Repositories;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Marvel\Database\Models\Banner;
use Marvel\Traits\MediaManager;
use Illuminate\Http\Request;


class BannerRepository extends BaseRepository
{
    use MediaManager;
    /**
     * Configure the Model
     **/
    public function model()
    {
        return Banner::class;
    }
    public function getBanners()
    {
        $limit = request()->limit ?? 15;
        $active = request()->active ?? false;
        $banners = Banner::with('products')->when($active, fn($query) => $query->active())->ordered()->paginate($limit);
        return $banners;
    }

    public function createBanner(Request $request)
    {
        try {

            DB::beginTransaction();
            $banner = $this->create($request->except('image_desktop', 'image_mobile'));
            if ($request->has('products')) {
                $banner->products()->sync($request->products);
            }
            if ($request->has('image_desktop') || $request->has('image_mobile')) {
                if ($request->has('image_desktop')) {
                    if (!$this->uploadSingleImage($request, 'image_desktop', $banner, 'banners-desktop', 'banners')) {
                        throw new HttpException(422, 'Banner image upload failed, please check the file format or size.');
                    }
                }
                if ($request->has('image_mobile')) {
                    if (!$this->uploadSingleImage($request, 'image_mobile', $banner, 'banners-mobile', 'banners')) {
                        throw new HttpException(422, 'Banner image upload failed, please check the file format or size.');
                    }
                }
            }
            DB::commit();
            return $banner;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new HttpException(500, $e->getMessage());
        }
    }

    public function updateBanner(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $banner = $this->findOrFail($id);
            $banner->update($request->except('image_desktop', 'image_mobile'));
            if ($request->has('products')) {
                $banner->products()->sync($request->products);
            }
            if ($request->has('image_desktop')) {
                if (!$this->updateSingleImage($request, 'image_desktop', $banner, 'banners-desktop', 'banners')) {
                    throw new HttpException(422, 'Banner image upload failed, please check the file format or size.');
                }
            }
            if ($request->has('image_mobile')) {
                if (!$this->updateSingleImage($request, 'image_mobile', $banner, 'banners-mobile', 'banners')) {
                    throw new HttpException(422, 'Banner image upload failed, please check the file format or size.');
                }
            }
            DB::commit();
            return $banner;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new HttpException(400, NOT_FOUND);
        }
    }


    public function changeStatus($id)
    {
        try {
            $banner = $this->find($id);
            $banner->update(['status' => !$banner->status]);
            return $banner;
        } catch (\Exception $e) {
            throw new HttpException(400, NOT_FOUND);
        }
    }

    public function reorder(array $banners)
    {
        try {
            $this->setNewOrder($banners);
        } catch (\Exception $e) {
            throw new HttpException(500, $e->getMessage());
        }
    }
}
