<?php


namespace Marvel\Database\Repositories;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Marvel\Traits\MediaManager;
use Illuminate\Http\Request;
use Marvel\Database\Models\Slider;

class SliderRepository extends BaseRepository
{
    use MediaManager;
    /**
     * Configure the Model
     **/
    public function model()
    {
        return Slider::class;
    }
    public function getSliders(Request $request)
    {
        $limit = $request->per_page ?? $request->limit ?? 15;
        $active = filter_var($request->active, FILTER_VALIDATE_BOOLEAN);
        $order = $request->order;
        $sortedBy = $request->sortedBy ?? 'asc';

        $query = Slider::when($active, fn($q) => $q->active());

        if ($order && in_array($order, ['id', 'title', 'slug', 'order', 'status', 'created_at', 'updated_at'])) {
            $query = $query->orderBy($order, $sortedBy === 'desc' ? 'desc' : 'asc');
        } else {
            $query = $query->ordered();
        }

        return $query->paginate($limit);
    }

    public function createSlider(Request $request)
    {
        try {

            DB::beginTransaction();
            $slider = $this->create($request->except('image_desktop', 'image_mobile', 'products'));
            if ($request->has('image_desktop')) {
                if (!$this->uploadSingleImage($request, 'image_desktop', $slider, 'sliders-desktop', 'sliders')) {
                    throw new HttpException(422, 'Slider image upload failed, please check the file format or size.');
                }
            }
            if ($request->has('image_mobile')) {
                if (!$this->uploadSingleImage($request, 'image_mobile', $slider, 'sliders-mobile', 'sliders')) {
                    throw new HttpException(422, 'Slider image upload failed, please check the file format or size.');
                }
            }

            if ($request->has('products')) {
                $slider->products()->sync($request->products);
            }

            DB::commit();
            return $slider;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new HttpException(500, $e->getMessage());
        }
    }

    public function updateSlider(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $slider = $this->findOrFail($id);
            $slider->update($request->except('image_desktop', 'image_mobile', 'products'));
            if ($request->has('image_desktop')) {
                if (!$this->updateSingleImage($request, 'image_desktop', $slider, 'sliders-desktop', 'sliders')) {
                    throw new HttpException(422, 'Slider image upload failed, please check the file format or size.');
                }
            }
            if ($request->has('image_mobile')) {
                if (!$this->updateSingleImage($request, 'image_mobile', $slider, 'sliders-mobile', 'sliders')) {
                    throw new HttpException(422, 'Slider image upload failed, please check the file format or size.');
                }
            }

            if ($request->has('products')) {
                $slider->products()->sync($request->products);
            }

            DB::commit();
            return $slider;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new HttpException(400, NOT_FOUND);
        }
    }

    public function changeStatus($id)
    {
        try {
            $slider = $this->findOrFail($id);
            $slider->update(['status' => !$slider->status]);
            return $slider;
        } catch (\Exception $e) {
            throw new HttpException(400, NOT_FOUND);
        }
    }

    public function reorder(array $sliders)
    {
        try {
            $this->setNewOrder($sliders);
        } catch (\Exception $e) {
            throw new HttpException(500, $e->getMessage());
        }
    }
}