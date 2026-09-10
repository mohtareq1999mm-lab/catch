<?php

namespace App\Services\General;

use App\Traits\HasChannelFilter;
use Marvel\Database\Models\Brand;

class BrandService
{
    use HasChannelFilter;

    public function getBrands($request)
    {
        $limit = min(100, max(1, (int) $request->get('limit', 10)));
        $start_date = $request->query('start_date');
        $end_date   = $request->query('end_date');
        $brandsId = $request->query('brandsId');
        $order = $request->query('order', 'desc');

        $query = Brand::active()
            ->when($start_date, function ($query) use ($start_date) {
                $query->where('created_at', '>=', $start_date);
            })
            ->when($end_date, function ($query) use ($end_date) {
                $query->where('created_at', '<=', $end_date);
            });

        if (!empty($brandsId)) {
            $ids = is_array($brandsId) ? $brandsId : explode(',', $brandsId);
            $ids = array_filter($ids, 'is_numeric');
            if (!empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        return $query->orderBy('id', $order)->limit($limit)->get();
    }
    public function getBrandBySlug($slug)
    {
        $brand = Brand::active()->search('slug', $slug, app()->getLocale())->first();
        if ($brand) {
            $brand->load(['products' => function ($q) {
                $this->applyChannelHomeFilter($q);
                $q->withAvg(['reviews' => fn($q) => $q->approved()], 'rating');
            }]);
            app(ProductService::class)->enrichCollectionWithPricing($brand->products);
        }
        return $brand;
    }
    public function getBrandsProductsByQtySet($request)
    {
        $qty = min(100, max(1, (int) $request->query('limit', 10)));
        $qtyBrand = min(100, max(1, (int) $request->query('limit_brand', 10)));
        $start_date = $request->query('start_date', '');
        $end_date   = $request->query('end_date', '');

        $brands = Brand::active()
            ->when(!empty($start_date), function ($query) use ($start_date) {
                $query->where('created_at', '>=', $start_date);
            })
            ->when(!empty($end_date), function ($query) use ($end_date) {
                $query->where('created_at', '<=', $end_date);
            })
            ->with(['products' => function ($query) use ($qty) {
                $this->applyChannelHomeFilter($query);
                $query->with(['media'])->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')->limit($qty);
            }])
            ->limit($qtyBrand)
            ->get()
        ->pluck('products')
            ->flatten();

        return app(ProductService::class)->enrichCollectionWithPricing($brands);
    }
}
